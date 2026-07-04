<?php

declare(strict_types=1);

namespace JobWarden\Reaper;

use JobWarden\Models\Job;
use JobWarden\Models\JobAttempt;
use JobWarden\Recovery\RecoveryService;
use JobWarden\StateMachine\Exceptions\IllegalTransitionException;
use JobWarden\StateMachine\Exceptions\StaleFencingTokenException;
use JobWarden\StateMachine\StateMachine;
use JobWarden\StateMachine\TransitionContext;
use JobWarden\States\ActorType;
use JobWarden\States\AttemptState;
use JobWarden\States\JobState;
use JobWarden\Support\SqlTime;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tier 3 (spec §5.4) — the only timeout in the system. PIDs are useless across
 * hosts, so a dead/partitioned worker can only be caught by its OWN lease going
 * stale. Exactly one global reaper is active (LeaderLease). Detection keys on the
 * per-process identity (worker_id), NOT host_id: host_id is boot-stable and gets
 * reused when a process restarts on the same box, so a fresh incarnation's
 * heartbeat would mask the dead one under a shared host_id. worker_id is a fresh
 * UUID per (re)start — every attempt is already stamped with the worker that
 * claimed it — so a dead incarnation is always distinguishable from its
 * replacement. For each worker whose heartbeat has expired beyond the budget it
 * orphans that worker's in-flight attempts (bumping the fencing token so the dead
 * process is fenced out), then runs recovery — so an idempotent job re-runs
 * elsewhere. (The local reaper still keys on host_id because it /proc-verifies
 * each child and can therefore tell a reused host_id apart; Tier 3 cannot verify
 * across hosts, so it must key on the unique per-process id.)
 *
 * `stopped` is a live state here too, not just `active`/`starting`/`draining`/`dead`:
 * a supervisor that hits `drain_timeout` abandons its still-running children and
 * marks its OWN row `stopped` on the way out (Supervisor::shutdown()) — the
 * attempt it owned never got a chance to be finalized. A `stopped` row is just as
 * capable of stranding in-flight attempts as a `dead` one, and must be scanned the
 * same way once its heartbeat goes stale.
 */
final class GlobalReaper
{
    public function __construct(
        private readonly LeaderLease $lease,
        private readonly AttemptOrphaner $orphaner,
        private readonly StateMachine $stateMachine,
        private readonly RecoveryService $recovery,
    ) {
    }

    /** @return bool whether this instance is the leader (and therefore scanned) */
    public function tick(string $reaperId): bool
    {
        $ttl = (int) config('jobwarden.reaper.global_lease_ttl', 15);
        if (! $this->lease->acquire('global_reaper', $reaperId, $ttl)) {
            return false; // another reaper holds the lease
        }

        foreach ($this->deadWorkers() as $worker) {
            $this->reapWorker((string) $worker->id, (string) $worker->host_id, $reaperId);
        }

        $this->reconcileStrandedJobs($reaperId);

        return true;
    }

    /**
     * The aggregate-invariant backstop. A job in `running` whose current attempt
     * has already settled (succeeded / failed / orphaned / stopped) is the residue
     * of a process that died in the window between the attempt transition and the
     * job transition. Fencing already guarantees the presumed-dead worker cannot
     * double-run; this only heals the STRANDED job state. Leader-only, and gated
     * by a grace window so it never races a healthy worker's own completion.
     */
    private function reconcileStrandedJobs(string $reaperId): void
    {
        foreach ($this->strandedJobIds() as $jobId) {
            try {
                $this->connection()->transaction(function () use ($jobId, $reaperId): void {
                    /** @var Job|null $job */
                    $job = Job::query()->find($jobId);
                    if ($job === null || $job->state !== JobState::Running) {
                        return; // resolved out from under us
                    }

                    $attempt = $job->current_attempt_id !== null
                        ? JobAttempt::query()->find($job->current_attempt_id)
                        : null;
                    if ($attempt === null || $attempt->state->isInFlight()) {
                        return; // nothing settled to reconcile against
                    }

                    $this->resolveStranded($job, $attempt, $reaperId);
                });
            } catch (IllegalTransitionException|StaleFencingTokenException) {
                // Raced a live worker's own completion — it won; nothing to do.
            }
        }
    }

    private function resolveStranded(Job $job, JobAttempt $attempt, string $reaperId): void
    {
        $reason = 'reconcile: attempt '.$attempt->state->value.' but job left running';

        match ($attempt->state) {
            AttemptState::Succeeded => $this->stateMachine->applyJobTransition(
                $job, JobState::Succeeded, TransitionContext::for(ActorType::System, $reaperId, $reason)),
            AttemptState::Failed => $this->recovery->afterAttemptFailure($job, ActorType::System, $reason),
            AttemptState::Stopped, AttemptState::Canceled => $this->stateMachine->applyJobTransition(
                $job, JobState::Stopped, TransitionContext::for(ActorType::System, $reaperId, $reason)),
            AttemptState::Orphaned => $this->reconcileOrphan($job, $reaperId, $reason),
            default => null, // in-flight — guarded against above
        };

        Log::warning('global reaper: reconciled a stranded job', [
            'role' => 'global_reaper',
            'reaper_id' => $reaperId,
            'job_id' => (string) $job->id,
            'attempt_state' => $attempt->state->value,
        ]);
    }

    private function reconcileOrphan(Job $job, string $reaperId, string $reason): void
    {
        $this->stateMachine->applyJobTransition($job, JobState::Orphaned, TransitionContext::for(ActorType::System, $reaperId, $reason));
        $this->recovery->resolveOrphan($job->refresh(), ActorType::System, $reason);
    }

    /**
     * Candidate jobs: `running` with a settled current attempt whose settlement is
     * older than the grace window. finished_at covers succeeded/failed/stopped;
     * orphaned bumps updated_at only, so COALESCE falls back to that.
     *
     * @return string[]
     */
    private function strandedJobIds(): array
    {
        $conn = $this->connection();
        $grace = (int) config('jobwarden.reaper.reconcile_grace_sec', 30);

        return $conn->table($this->tbl('jobs').' as j')
            ->join($this->tbl('job_attempts').' as a', 'a.id', '=', 'j.current_attempt_id')
            ->where('j.state', JobState::Running->value)
            ->whereNotIn('a.state', [AttemptState::Dispatched->value, AttemptState::Running->value])
            ->whereRaw('COALESCE(a.finished_at, a.updated_at) < '.SqlTime::nowMinus($conn, $grace))
            ->orderBy('j.id')
            ->limit(500)
            ->pluck('j.id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }

    public function budgetSeconds(): int
    {
        return (int) config('jobwarden.host_lease.heartbeat_interval', 10)
            * (int) config('jobwarden.host_lease.missed_beats', 3);
    }

    /**
     * Workers (job-claiming processes) whose OWN heartbeat has gone stale beyond
     * the budget. Keyed per worker_id — a fresh incarnation under the same
     * host_id cannot mask a dead one, because they are distinct worker rows.
     *
     * @return object[] rows of {id, host_id}
     */
    private function deadWorkers(): array
    {
        $conn = $this->connection();

        return $conn->table($this->tbl('workers'))
            ->select('id', 'host_id')
            ->where('role', 'supervisor')  // only job-claiming workers stamp attempts
            // Stale-beyond-budget = not-alive. Include `dead`, not just the live
            // states: a prior reap can mark a worker `dead` yet die before finishing
            // its orphan pass (or be killed mid-loop by a container teardown),
            // stranding that worker's in-flight attempts forever because a `dead`
            // row was never re-scanned. Also include `stopped`: a supervisor that
            // abandons children after `drain_timeout` sets its own row `stopped`
            // while still owning an in-flight attempt. Gate on actually owning an
            // in-flight attempt so we don't re-touch already-cleaned rows on every
            // scan (a normal graceful stop never has one, so this can't misfire).
            ->whereIn('state', ['active', 'starting', 'draining', 'dead', 'stopped'])
            ->whereRaw('heartbeat_at < '.SqlTime::nowMinus($conn, $this->budgetSeconds()))
            ->whereExists(function ($q) use ($conn): void {
                $q->selectRaw('1')
                    ->from($this->tbl('job_attempts').' as a')
                    ->whereColumn('a.worker_id', $this->tbl('workers').'.id')
                    ->whereIn('a.state', [AttemptState::Dispatched->value, AttemptState::Running->value]);
            })
            ->get()
            ->all();
    }

    private function reapWorker(string $workerId, string $hostId, string $reaperId): void
    {
        $conn = $this->connection();

        // Declare this specific dead process's row dead (no skew — DB clock). A
        // `stopped` row (drain-timeout abandonment) is reclassified to `dead` too,
        // so the dashboard reflects that it stranded work rather than stopping clean.
        $conn->table($this->tbl('workers'))
            ->where('id', $workerId)
            ->whereIn('state', ['active', 'starting', 'draining', 'stopped'])
            ->update(['state' => 'dead', 'stopped_at' => $conn->raw('CURRENT_TIMESTAMP'), 'last_signal' => 'worker_dead']);

        $attempts = JobAttempt::query()
            ->where('worker_id', $workerId)
            ->whereIn('state', [AttemptState::Dispatched->value, AttemptState::Running->value])
            ->get();

        if ($attempts->isEmpty()) {
            return; // stale worker with no in-flight work — nothing to recover
        }

        Log::warning('global reaper: worker declared dead', [
            'role' => 'global_reaper',
            'reaper_id' => $reaperId,
            'worker_id' => $workerId,
            'host_id' => $hostId,
            'orphaning_attempts' => $attempts->count(),
            'budget_sec' => $this->budgetSeconds(),
        ]);

        foreach ($attempts as $attempt) {
            $this->orphaner->orphan($attempt, $reaperId, (string) $attempt->host_id, 'global', 'worker dead: '.$workerId);
        }
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
