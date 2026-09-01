<?php

declare(strict_types=1);

namespace JobWarden\Health;

use JobWarden\Models\Job;
use JobWarden\Models\Worker;
use JobWarden\States\BatchState;
use JobWarden\States\JobState;
use JobWarden\Support\SqlTime;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * "Why hasn't this job started yet?" — the read-side mirror of the admission
 * path, for a job sitting in pending/queued/retrying.
 *
 * The engine's own tabs can only report what already happened, so a waiting job
 * renders as four empty panes. Every gate that holds it back is knowable
 * exactly, though, because admission is a closed set of predicates:
 *
 *   pending → queued   available_at due, no unmet job edge, no unmet batch edge
 *                      (Admitter's window + DepsSatisfiedGuard + AvailableAtReachedGuard),
 *                      then any live supervisor's admit pass — which is NOT
 *                      lane-scoped, so a pending job is admitted even into a lane
 *                      with no workers.
 *   retrying → queued  available_at (the backoff expiry) only; deliberately not
 *                      dep-guarded, see Admitter::promote().
 *   queued → running   available_at due again, a live supervisor ON THIS LANE,
 *                      a free slot on it, and reaching the front of
 *                      `ORDER BY priority DESC, created_at ASC` (SkipLockedClaimDriver).
 *
 * The two dependency predicates here MUST stay identical to DepsSatisfiedGuard's
 * (which the Admitter window already duplicates — three sites now). They are
 * written as the same carve-out from the strict rule, inverted to SELECT the
 * offending edges instead of counting them: a panel that reports a different
 * answer than the engine acts on is worse than no panel at all.
 *
 * Every comparison is evaluated against the DB clock, never the app clock —
 * available_at, created_at, and heartbeat_at all live in the DB's timezone
 * frame (spec §5.3).
 */
final class WaitAnalysis
{
    /** Backlog counting stops here; beyond it the exact number is noise. */
    public const AHEAD_CAP = 100;

    /** Unmet edges listed per panel before collapsing into "+ N more". */
    public const DEP_LIST_CAP = 20;

    /** Worker states that still count as present (matches Livewire\Workers). */
    private const LIVE = ['starting', 'active', 'draining'];

    private const JOB_TERMINAL = [
        JobState::Succeeded->value, JobState::Failed->value,
        JobState::Canceled->value, JobState::Stopped->value,
    ];

    private const BATCH_TERMINAL = [
        BatchState::Succeeded->value, BatchState::Failed->value, BatchState::Partial->value,
        BatchState::Canceled->value, BatchState::Stopped->value,
    ];

    /**
     * Null for a job that is not waiting (running or terminal) — the caller
     * renders nothing, and pays for nothing.
     *
     * @return array{
     *   state: string,
     *   headline: array{text: string, at_ms: int|null, tone: string},
     *   due: array{ok: bool, at_ms: int|null},
     *   job_deps: array{items: list<array{id: string, label: string, state: string, edge_condition: string}>, more: bool},
     *   batch_deps: array{items: list<array{id: string, label: string, state: string, edge_condition: string, in_flight: int}>, more: bool},
     *   lane: array{lane: string, supervisors: int, stale: int, capacity: int, load: int, free: int, fleet: int},
     *   ahead: array{count: int, capped: bool}|null,
     * }|null
     */
    public static function for(Job $job): ?array
    {
        $state = $job->state instanceof JobState ? $job->state : JobState::from((string) $job->state);

        if (! in_array($state, [JobState::Pending, JobState::Queued, JobState::Retrying], true)) {
            return null;
        }

        $self = new self;

        $due = $self->due($job);
        $jobDeps = $state === JobState::Pending ? $self->jobDeps($job) : ['items' => [], 'more' => false];
        $batchDeps = $state === JobState::Pending ? $self->batchDeps($job) : ['items' => [], 'more' => false];
        $lane = $self->lane($job);
        $ahead = $state === JobState::Queued ? $self->ahead($job) : null;

        return [
            'state' => $state->value,
            'headline' => $self->headline($job, $state, $due, $jobDeps, $batchDeps, $lane, $ahead),
            'due' => $due,
            'job_deps' => $jobDeps,
            'batch_deps' => $batchDeps,
            'lane' => $lane,
            'ahead' => $ahead,
        ];
    }

    /**
     * The availability gate, evaluated server-side exactly like
     * AvailableAtReachedGuard — reading the column back through Eloquent would
     * re-parse it in the app timezone and give a wrong absolute instant under
     * any TZ mismatch. The epoch comes back with it so the browser can render
     * the countdown in the viewer's own zone.
     *
     * @return array{ok: bool, at_ms: int|null}
     */
    private function due(Job $job): array
    {
        $conn = $this->connection();
        $now = SqlTime::nowExpr($conn);
        $epoch = SqlTime::epochMsExpr($conn, 'available_at');

        $row = $conn->selectOne(
            "SELECT CASE WHEN available_at IS NULL OR available_at <= {$now} THEN 1 ELSE 0 END AS ok,"
            ." {$epoch} AS at_ms FROM {$this->tbl('jobs')} WHERE id = ?",
            [$job->getKey()]
        );

        return [
            'ok' => (int) ($row->ok ?? 1) === 1,
            'at_ms' => isset($row->at_ms) && $row->at_ms !== null ? (int) $row->at_ms : null,
        ];
    }

    /**
     * Unmet DAG edges. Same predicate as DepsSatisfiedGuard: an on_success edge
     * needs the upstream SUCCEEDED, an on_completion edge merely needs it ended.
     * Written as a carve-out from the strict rule so an unrecognized
     * edge_condition keeps reporting the strict (safe) answer.
     *
     * Note `orphaned` is deliberately absent from JOB_TERMINAL — a parked orphan
     * awaits an operator verdict, so it gates its dependents under EITHER
     * condition. That is the blocker operators misread most often, so the view
     * calls it out by name.
     *
     * @return array{items: list<array{id: string, label: string, state: string, edge_condition: string}>, more: bool}
     */
    private function jobDeps(Job $job): array
    {
        $rows = $this->connection()->table($this->tbl('job_dependencies').' as d')
            ->join($this->tbl('jobs').' as dep', 'dep.id', '=', 'd.depends_on_job_id')
            ->where('d.job_id', $job->getKey())
            ->where('dep.state', '!=', JobState::Succeeded->value)
            ->where(fn ($q) => $q
                ->where('d.edge_condition', '!=', 'on_completion')
                ->orWhereNotIn('dep.state', self::JOB_TERMINAL))
            ->orderBy('dep.created_at')
            ->limit(self::DEP_LIST_CAP + 1)
            ->get(['dep.id', 'dep.job_class', 'dep.name', 'dep.state', 'd.edge_condition']);

        return [
            'items' => $rows->take(self::DEP_LIST_CAP)->map(fn ($r): array => [
                'id' => (string) $r->id,
                'label' => (string) ($r->name ?? class_basename((string) $r->job_class)),
                'state' => (string) $r->state,
                'edge_condition' => (string) $r->edge_condition,
            ])->values()->all(),
            'more' => $rows->count() > self::DEP_LIST_CAP,
        ];
    }

    /**
     * Unmet cross-batch edges. `on_completion` here means terminal AND quiescent
     * — a failed batch still draining its spared finalizer subtree has not
     * finished, so `in_flight` (pending + running) is carried through to explain
     * a batch that looks done but still gates.
     *
     * @return array{items: list<array{id: string, label: string, state: string, edge_condition: string, in_flight: int}>, more: bool}
     */
    private function batchDeps(Job $job): array
    {
        $rows = $this->connection()->table($this->tbl('job_batch_dependencies').' as bd')
            ->join($this->tbl('batches').' as b', 'b.id', '=', 'bd.depends_on_batch_id')
            ->where('bd.job_id', $job->getKey())
            ->where('b.state', '!=', BatchState::Succeeded->value)
            ->where(fn ($q) => $q
                ->where('bd.edge_condition', '!=', 'on_completion')
                ->orWhereNotIn('b.state', self::BATCH_TERMINAL)
                ->orWhereRaw('b.pending_count + b.running_count > 0'))
            ->limit(self::DEP_LIST_CAP + 1)
            ->get(['b.id', 'b.name', 'b.state', 'b.pending_count', 'b.running_count', 'bd.edge_condition']);

        return [
            'items' => $rows->take(self::DEP_LIST_CAP)->map(fn ($r): array => [
                'id' => (string) $r->id,
                'label' => (string) ($r->name ?? substr((string) $r->id, 0, 8)),
                'state' => (string) $r->state,
                'edge_condition' => (string) $r->edge_condition,
                'in_flight' => (int) $r->pending_count + (int) $r->running_count,
            ])->values()->all(),
            'more' => $rows->count() > self::DEP_LIST_CAP,
        ];
    }

    /**
     * Who is actually able to run this job's lane.
     *
     * A lane with work and no supervisor is the one fleet failure the engine
     * cannot report on its own — recovery only ever recovers work, never
     * processes (see Livewire\Workers::laneCoverage). This narrows that view to
     * one job, and additionally discounts supervisors whose lease has expired:
     * the global reaper will flip them to `dead`, but until it does, a row in
     * `active` with a stale heartbeat is a process that stopped claiming, and
     * counting it as coverage would hide exactly the outage we're looking for.
     * Capacity and load therefore sum only the fresh ones.
     *
     * `lane` lives in the worker's meta JSON, so the grouping is done in PHP —
     * same as laneCoverage, and the fleet is small by construction.
     *
     * @return array{lane: string, supervisors: int, stale: int, capacity: int, load: int, free: int, fleet: int}
     */
    private function lane(Job $job): array
    {
        $conn = $this->connection();
        $ttl = (int) config('jobwarden.host_lease.heartbeat_interval', 10)
            * (int) config('jobwarden.host_lease.missed_beats', 3);
        $cutoff = SqlTime::nowMinus($conn, $ttl);

        $supervisors = Worker::query()
            ->whereIn('state', self::LIVE)
            ->where('role', 'supervisor')
            ->selectRaw('*, CASE WHEN heartbeat_at IS NULL OR heartbeat_at < '.$cutoff.' THEN 1 ELSE 0 END AS jw_stale')
            ->get();

        $lane = (string) ($job->lane ?? 'default');
        $onLane = $supervisors->filter(fn (Worker $w): bool => (string) ($w->meta['lane'] ?? 'default') === $lane);
        $fresh = $onLane->reject(fn (Worker $w): bool => (bool) $w->jw_stale);

        $capacity = (int) $fresh->sum('capacity');
        $load = (int) $fresh->sum('current_load');

        return [
            'lane' => $lane,
            'supervisors' => $fresh->count(),
            'stale' => $onLane->count() - $fresh->count(),
            'capacity' => $capacity,
            'load' => $load,
            'free' => max(0, $capacity - $load),
            // The admit pass is not lane-scoped: ANY live supervisor promotes for
            // every lane, so pending/retrying care about the fleet, not the lane.
            'fleet' => $supervisors->reject(fn (Worker $w): bool => (bool) $w->jw_stale)->count(),
        ];
    }

    /**
     * How many queued jobs in the same lane the claim would take first, under
     * `ORDER BY priority DESC, created_at ASC`.
     *
     * Counted through a LIMITed derived table, not `->limit()->count()` — the
     * builder applies a limit to the aggregate's single result row, not to the
     * scan, so the naive form would still walk an entire backlog on every
     * dashboard poll. The pivot values are read from the job's own row inside
     * SQL rather than bound from PHP, keeping created_at in the DB's timezone
     * frame. The predicate matches jobs_claim_idx (lane, state, priority DESC,
     * created_at) on its leading columns.
     *
     * @return array{count: int, capped: bool}
     */
    private function ahead(Job $job): array
    {
        $jobs = $this->tbl('jobs');
        $cap = self::AHEAD_CAP + 1;

        $row = $this->connection()->selectOne(
            "SELECT COUNT(*) AS c FROM ("
            ."SELECT 1 FROM {$jobs} q, {$jobs} self"
            ." WHERE self.id = ? AND q.lane = self.lane AND q.state = ?"
            ." AND (q.priority > self.priority"
            ." OR (q.priority = self.priority AND q.created_at < self.created_at))"
            ." LIMIT {$cap}"
            .") AS capped",
            [$job->getKey(), JobState::Queued->value]
        );

        $count = (int) ($row->c ?? 0);

        return [
            'count' => min($count, self::AHEAD_CAP),
            'capped' => $count > self::AHEAD_CAP,
        ];
    }

    /**
     * The one sentence that answers the question, picked as the FIRST gate that
     * is actually holding the job — same order the engine evaluates them in.
     * `at_ms`, when set, is rendered by the view as a browser-local countdown.
     *
     * @param array{ok: bool, at_ms: int|null} $due
     * @param array{items: list<array<string, mixed>>, more: bool} $jobDeps
     * @param array{items: list<array<string, mixed>>, more: bool} $batchDeps
     * @param array{lane: string, supervisors: int, stale: int, capacity: int, load: int, free: int, fleet: int} $lane
     * @param array{count: int, capped: bool}|null $ahead
     * @return array{text: string, at_ms: int|null, tone: string}
     */
    private function headline(Job $job, JobState $state, array $due, array $jobDeps, array $batchDeps, array $lane, ?array $ahead): array
    {
        $plain = fn (string $text, string $tone): array => ['text' => $text, 'at_ms' => null, 'tone' => $tone];

        // Nothing will run a job whose cancel is already requested; say so first.
        if ($job->cancel_requested) {
            return $plain(($job->cancel_mode ?? 'cancel').' requested — this job will not start', 'gray');
        }

        if ($state === JobState::Queued) {
            if (! $due['ok']) {
                return ['text' => 'Held until', 'at_ms' => $due['at_ms'], 'tone' => 'blue'];
            }

            if ($lane['supervisors'] === 0) {
                return $plain($lane['stale'] > 0
                    ? "Lane {$lane['lane']} has no live supervisor — {$lane['stale']} present but past its lease"
                    : "No supervisor is running lane {$lane['lane']}", 'red');
            }

            if ($lane['free'] === 0) {
                return $plain("Lane {$lane['lane']} is saturated — all {$lane['capacity']} slots busy", 'amber');
            }

            $n = $ahead['count'] ?? 0;

            if ($n === 0) {
                return $plain("Next up on lane {$lane['lane']}", 'blue');
            }

            return $plain($this->count($ahead, 'job').' ahead on lane '.$lane['lane'], 'amber');
        }

        if ($state === JobState::Retrying) {
            if (! $due['ok']) {
                return [
                    'text' => "Backoff (attempt {$job->attempt_count} of {$job->max_attempts}) — retries",
                    'at_ms' => $due['at_ms'],
                    'tone' => 'amber',
                ];
            }

            return $lane['fleet'] === 0
                ? $plain('No live supervisor — nothing is running the admit pass', 'red')
                : $plain('Backoff elapsed — awaiting the next admit pass', 'blue');
        }

        $blocked = count($jobDeps['items']) + count($batchDeps['items']);

        if ($blocked > 0) {
            $parts = [];
            if ($jobDeps['items'] !== []) {
                $parts[] = $this->plural(count($jobDeps['items']), 'upstream job', $jobDeps['more']);
            }
            if ($batchDeps['items'] !== []) {
                $parts[] = $this->plural(count($batchDeps['items']), 'upstream batch', $batchDeps['more'], 'upstream batches');
            }

            return $plain('Blocked on '.implode(' and ', $parts), 'amber');
        }

        if (! $due['ok']) {
            return ['text' => 'Not due — scheduled for', 'at_ms' => $due['at_ms'], 'tone' => 'blue'];
        }

        return $lane['fleet'] === 0
            ? $plain('No live supervisor — nothing is running the admit pass', 'red')
            : $plain('Eligible — awaiting the next admit pass', 'blue');
    }

    /** @param array{count: int, capped: bool}|null $ahead */
    private function count(?array $ahead, string $noun): string
    {
        $n = $ahead['count'] ?? 0;

        return ($ahead['capped'] ?? false)
            ? $n.'+ '.$noun.'s'
            : $n.' '.$noun.($n === 1 ? '' : 's');
    }

    private function plural(int $n, string $singular, bool $more, ?string $plural = null): string
    {
        $noun = $n === 1 && ! $more ? $singular : ($plural ?? $singular.'s');

        return ($more ? $n.'+' : (string) $n).' '.$noun;
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
