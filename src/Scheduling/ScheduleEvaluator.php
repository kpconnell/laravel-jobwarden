<?php

declare(strict_types=1);

namespace JobWarden\Scheduling;

use JobWarden\Models\Job;
use JobWarden\Models\Schedule;
use JobWarden\States\JobState;
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
            // Skip if another scheduler is already evaluating this schedule.
            $locked = $conn->table($this->tbl('schedules'))
                ->where('id', $scheduleId)->where('enabled', true)
                ->lock('for update skip locked')->first();

            if ($locked === null) {
                return 0;
            }

            $schedule = Schedule::findOrFail($scheduleId);
            $now = Carbon::now();
            $after = $schedule->last_evaluated_at ?? $now->copy()->subSecond();

            $occurrences = $this->calculator->occurrences($schedule, $after, $now);
            $enqueued = $occurrences === [] ? 0 : $this->materialize($schedule, $occurrences, $now, $evaluatorWorkerId);

            $schedule->forceFill([
                'last_evaluated_at' => $now,
                'next_due_at' => $this->calculator->nextDueAfter($schedule, $now),
            ])->save();

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
            if ($this->recordRun($schedule, $occ, 'enqueued', $now, $evaluatorWorkerId)) {
                $job = $this->createJob($schedule, $occ, $now);
                $this->linkJob($schedule, $occ, (string) $job->id);
                $enqueued++;
            }
        }
        foreach ($coalesced as $occ) {
            $this->recordRun($schedule, $occ, 'coalesced', $now, $evaluatorWorkerId);
        }
        foreach ($skipped as $occ) {
            $this->recordRun($schedule, $occ, 'skipped', $now, $evaluatorWorkerId);
        }
        foreach ($outsideWindow as $occ) {
            $this->recordRun($schedule, $occ, 'outside_window', $now, $evaluatorWorkerId);
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

    private function recordRun(Schedule $schedule, Carbon $occ, string $action, Carbon $now, ?string $evaluatorWorkerId): bool
    {
        // INSERT … ON CONFLICT DO NOTHING — the lynchpin of multi-scheduler safety.
        $affected = $this->connection()->table($this->tbl('schedule_runs'))->insertOrIgnore([
            'id' => (string) Uuid::v7(),
            'schedule_id' => $schedule->id,
            'occurrence_time' => $occ,
            'detected_at' => $now,
            'action' => $action,
            'evaluator_worker_id' => $evaluatorWorkerId,
            'created_at' => $now,
        ]);

        return $affected === 1; // true = materialized for the FIRST time
    }

    private function createJob(Schedule $schedule, Carbon $occ, Carbon $now): Job
    {
        $params = (array) ($schedule->params ?? []);

        return Job::create([
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
            'available_at' => $occ->greaterThan($now) ? $occ : $now,
            // Idempotent runs need a budget > 1 to actually retry on host-loss;
            // non-idempotent runs are single-shot (they park, they don't retry).
            'max_attempts' => (int) ($schedule->max_attempts ?? ($schedule->idempotent ? 3 : 1)),
            'attempt_count' => 0,
            'queued_at' => $now,
        ]);
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

    private function connection(): Connection
    {
        return DB::connection(config('jobwarden.connection'));
    }

    private function tbl(string $name): string
    {
        return ((string) config('jobwarden.table_prefix')).$name;
    }
}
