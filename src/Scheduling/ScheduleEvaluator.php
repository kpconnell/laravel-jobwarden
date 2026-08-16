<?php

declare(strict_types=1);

namespace JobWarden\Scheduling;

use JobWarden\Models\Job;
use JobWarden\Models\Schedule;
use JobWarden\Search\TagWriter;
use JobWarden\States\JobState;
use JobWarden\Support\SqlTime;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\Uuid;

/**
 * Evaluates ONE schedule (spec §7.1). Holds the schedule row with FOR UPDATE SKIP
 * LOCKED for the evaluation, computes the occurrences in (last_evaluated_at, now],
 * applies missed_policy + overlap_policy + catch-up limits, and materializes each
 * via INSERT … ON CONFLICT DO NOTHING on UNIQUE(schedule_id, occurrence_time) —
 * so concurrent schedulers can never double-enqueue an occurrence.
 *
 * EVERY instant here lives on the DB clock, both directions (spec §5.3):
 *
 *  - WRITES go through SqlTime::nowExpr()/nowPlus(), never a bound Carbon. Laravel
 *    formats a Carbon binding as 'Y-m-d H:i:s' — the offset is DROPPED — so a
 *    Carbon::now() under an app.timezone that differs from the DB session zone
 *    stores local wall-clock as if it were the DB's own frame. That skewed
 *    `available_at` is compared against CURRENT_TIMESTAMP by the claim, so an
 *    ahead-of-DB app.timezone made scheduled jobs unclaimable for the offset.
 *  - READS of the columns we then do window math on (last_evaluated_at, run_at)
 *    come back as epochs via SqlTime::epochMsExpr(), not through Eloquent's
 *    datetime cast. MariaDB hands a tz-aware column back as a bare string, which
 *    the cast parses in app.timezone — so a DB-frame last_evaluated_at read that
 *    way lands hours off, and an app.timezone ahead of the DB puts it in the
 *    FUTURE: the window is empty on every tick and the schedule silently stops
 *    firing. The write fix alone would have introduced exactly that.
 *
 * occurrence_time stays as it is: it is the UNIQUE dedup key, so its stored value
 * must be byte-stable across evaluations, and it is already written in the UTC
 * frame the rest of the row now shares.
 */
final class ScheduleEvaluator
{
    public function __construct(private readonly OccurrenceCalculator $calculator)
    {
    }

    /** @return int jobs enqueued */
    public function evaluate(string $scheduleId, ?string $evaluatorWorkerId = null): int
    {
        $conn = $this->connection();

        return (int) $conn->transaction(function () use ($conn, $scheduleId, $evaluatorWorkerId): int {
            // Skip if another scheduler is already evaluating this schedule. The same
            // locked read carries the two instants we do window math on, as epochs.
            $locked = $conn->table($this->tbl('schedules'))
                ->select('*')
                ->selectRaw(SqlTime::epochMsExpr($conn, 'last_evaluated_at').' as jw_last_evaluated_ms')
                ->selectRaw(SqlTime::epochMsExpr($conn, 'run_at').' as jw_run_at_ms')
                ->where('id', $scheduleId)->where('enabled', true)
                ->lock('for update skip locked')->first();

            if ($locked === null) {
                return 0;
            }

            $schedule = Schedule::findOrFail($scheduleId);
            $now = SqlTime::now($conn);

            // The model carries the schedule's CONFIG; its instants come from the epochs
            // above. Never assign these back onto the model — Eloquent's datetime cast
            // re-serializes a Carbon through a bare app-timezone string on the way in.
            $lastEvaluatedAt = $this->instant($locked->jw_last_evaluated_ms ?? null);
            $runAt = $this->instant($locked->jw_run_at_ms ?? null);

            $after = $lastEvaluatedAt ?? $now->copy()->subSecond();

            $occurrences = $this->calculator->occurrences($schedule, $after, $now, $runAt);
            $enqueued = $occurrences === [] ? 0 : $this->materialize($schedule, $occurrences, $now, $evaluatorWorkerId);

            $nextDue = $this->calculator->nextDueAfter($schedule, $now, $runAt);
            $conn->table($this->tbl('schedules'))->where('id', $scheduleId)->update([
                'last_evaluated_at' => $conn->raw(SqlTime::nowExpr($conn)),
                'next_due_at' => $nextDue === null ? null : $conn->raw($this->atExpr($conn, $now, $nextDue)),
                // Stamped here too: an Eloquent save() would write updated_at with
                // Carbon::now(), i.e. back in the app frame we just left.
                'updated_at' => $conn->raw(SqlTime::nowExpr($conn)),
            ]);

            return $enqueued;
        });
    }

    /** @param Carbon[] $occurrences */
    private function materialize(Schedule $schedule, array $occurrences, Carbon $now, ?string $evaluatorWorkerId): int
    {
        // 1. catch-up window: occurrences too old to bother with.
        $inWindow = [];
        $outsideWindow = [];
        $windowFloor = $schedule->catch_up_window_sec !== null ? $now->copy()->subSeconds((int) $schedule->catch_up_window_sec) : null;
        foreach ($occurrences as $occ) {
            if ($windowFloor !== null && $occ->lessThan($windowFloor)) {
                $outsideWindow[] = $occ;
            } else {
                $inWindow[] = $occ;
            }
        }

        // 2. missed_policy → which occurrences enqueue, which are coalesced/skipped.
        [$toEnqueue, $coalesced, $skipped] = $this->applyMissedPolicy($schedule, $inWindow);

        // 3. max_catch_up cap (keep the most recent N).
        if ($schedule->max_catch_up !== null && count($toEnqueue) > (int) $schedule->max_catch_up) {
            $keep = (int) $schedule->max_catch_up;
            $skipped = array_merge($skipped, array_slice($toEnqueue, 0, count($toEnqueue) - $keep));
            $toEnqueue = array_slice($toEnqueue, -$keep);
        }

        // 4. overlap_policy=skip: don't enqueue while a job from this schedule is active.
        if ($schedule->overlap_policy === 'skip' && $this->hasActiveJob($schedule)) {
            $skipped = array_merge($skipped, $toEnqueue);
            $toEnqueue = [];
        }

        $enqueued = 0;
        foreach ($toEnqueue as $occ) {
            if ($this->recordRun($schedule, $occ, 'enqueued', $evaluatorWorkerId)) {
                $job = $this->createJob($schedule, $occ, $now);
                $this->linkJob($schedule, $occ, (string) $job->id);
                $enqueued++;
            }
        }
        foreach ($coalesced as $occ) {
            $this->recordRun($schedule, $occ, 'coalesced', $evaluatorWorkerId);
        }
        foreach ($skipped as $occ) {
            $this->recordRun($schedule, $occ, 'skipped', $evaluatorWorkerId);
        }
        foreach ($outsideWindow as $occ) {
            $this->recordRun($schedule, $occ, 'outside_window', $evaluatorWorkerId);
        }

        return $enqueued;
    }

    /**
     * @param Carbon[] $inWindow
     * @return array{0: Carbon[], 1: Carbon[], 2: Carbon[]} [enqueue, coalesced, skipped]
     */
    private function applyMissedPolicy(Schedule $schedule, array $inWindow): array
    {
        if ($inWindow === []) {
            return [[], [], []];
        }

        return match ($schedule->missed_policy) {
            'run_all' => [$inWindow, [], []],
            'run_latest' => [[end($inWindow)], [], array_slice($inWindow, 0, -1)],
            'coalesce' => [[end($inWindow)], array_slice($inWindow, 0, -1), []],
            'skip' => [[], [], $inWindow],
            default => [$inWindow, [], []],
        };
    }

    private function recordRun(Schedule $schedule, Carbon $occ, string $action, ?string $evaluatorWorkerId): bool
    {
        $conn = $this->connection();

        // INSERT … ON CONFLICT DO NOTHING — the lynchpin of multi-scheduler safety.
        $affected = $conn->table($this->tbl('schedule_runs'))->insertOrIgnore([
            'id' => (string) Uuid::v7(),
            'schedule_id' => $schedule->id,
            'occurrence_time' => $occ,
            'detected_at' => $conn->raw(SqlTime::nowExpr($conn)),
            'action' => $action,
            'evaluator_worker_id' => $evaluatorWorkerId,
            'created_at' => $conn->raw(SqlTime::nowExpr($conn)),
        ]);

        return $affected === 1; // true = materialized for the FIRST time
    }

    private function createJob(Schedule $schedule, Carbon $occ, Carbon $now): Job
    {
        $params = (array) ($schedule->params ?? []);

        $job = Job::create([
            'schedule_id' => $schedule->id,
            'job_class' => $schedule->job_class,
            'name' => $schedule->name,
            // Everything the scheduler emits runs on the dedicated scheduled-tier
            // runner, never the business fleet (spec §7, the scheduled lane).
            'lane' => 'scheduled',
            'params' => $params,
            // The operator's idempotency declaration on the schedule drives host-loss
            // recovery of this run (the binary guard: idempotent → retry, else park).
            'idempotent' => (bool) $schedule->idempotent,
            'priority' => (int) $schedule->priority,
            'state' => JobState::Queued,
            // Idempotent runs need a budget > 1 to actually retry on host-loss;
            // non-idempotent runs are single-shot (they park, they don't retry).
            'max_attempts' => (int) ($schedule->max_attempts ?? ($schedule->idempotent ? 3 : 1)),
            'attempt_count' => 0,
        ]);

        // available_at / queued_at on the DB clock, exactly as JobWarden::dispatch()
        // stamps them: the claim gates on `available_at <= CURRENT_TIMESTAMP`, so a
        // bound Carbon here would make the run early or late by the app↔DB offset.
        // Both writes land in the surrounding evaluation transaction.
        $conn = $this->connection();
        $conn->table($job->getTable())
            ->where($job->getKeyName(), $job->getKey())
            ->update([
                'available_at' => $conn->raw($occ->greaterThan($now) ? $this->atExpr($conn, $now, $occ) : SqlTime::nowExpr($conn)),
                'queued_at' => $conn->raw(SqlTime::nowExpr($conn)),
            ]);

        // Schedule tags carry to every run it spawns (plus param promotion).
        // Runtime path: silently keep only valid string=>string entries — a
        // malformed tag on a schedule row must not stop the scheduler.
        $tags = array_filter(
            (array) ($schedule->tags ?? []),
            static fn ($value, $name): bool => is_string($name) && $name !== '' && mb_strlen($name) <= TagWriter::MAX_NAME
                && is_string($value) && $value !== '' && mb_strlen($value) <= TagWriter::MAX_VALUE,
            ARRAY_FILTER_USE_BOTH,
        );
        TagWriter::write($job, $tags);

        return $job;
    }

    private function linkJob(Schedule $schedule, Carbon $occ, string $jobId): void
    {
        $this->connection()->table($this->tbl('schedule_runs'))
            ->where('schedule_id', $schedule->id)
            ->where('occurrence_time', $occ)
            ->update(['job_id' => $jobId]);
    }

    private function hasActiveJob(Schedule $schedule): bool
    {
        $terminal = [JobState::Succeeded->value, JobState::Failed->value, JobState::Canceled->value, JobState::Stopped->value];

        return Job::query()->where('schedule_id', $schedule->id)->whereNotIn('state', $terminal)->exists();
    }

    /**
     * A SQL expression placing an absolute instant in the DB's frame, as an offset from
     * the DB clock — the same conversion JobWarden::dispatch() applies to a caller-supplied
     * available_at. Second granularity: these are eligibility gates and display hints,
     * never the dedup key.
     */
    private function atExpr(Connection $conn, Carbon $now, Carbon $target): string
    {
        return SqlTime::nowPlus($conn, (int) ceil($now->diffInSeconds($target, false)));
    }

    /** A tz-safely-read epoch-ms column back as an absolute instant. */
    private function instant(int|float|string|null $epochMs): ?Carbon
    {
        return $epochMs === null ? null : Carbon::createFromTimestampMs((int) round((float) $epochMs));
    }

    private function connection(): Connection
    {
        return DB::connection(config('jobwarden.connection'));
    }

    private function tbl(string $name): string
    {
        return ((string) config('jobwarden.table_prefix')).$name;
    }
}
