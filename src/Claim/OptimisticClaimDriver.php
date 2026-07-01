<?php

declare(strict_types=1);

namespace JobWarden\Claim;

use JobWarden\Claim\Contracts\ClaimDriver;
use JobWarden\Models\Job;
use JobWarden\StateMachine\Exceptions\GuardFailedException;
use JobWarden\StateMachine\Exceptions\IllegalTransitionException;
use JobWarden\StateMachine\Exceptions\StaleFencingTokenException;
use JobWarden\StateMachine\StateMachine;
use JobWarden\StateMachine\TransitionContext;
use JobWarden\States\ActorType;
use JobWarden\States\AttemptState;
use JobWarden\States\JobState;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\Uuid;

/**
 * The fallback claim driver (spec §5.1) for concurrent engines that lack
 * `FOR UPDATE SKIP LOCKED` (MySQL < 8.0.1, MariaDB < 10.6). It selects candidates
 * WITHOUT a lock, then claims each with the universal guarded-UPDATE primitive:
 * the `queued → running` transition's `WHERE state='queued'` means exactly one
 * racer's UPDATE affects the row (`affected==1`); everyone else gets 0 and skips.
 * No row is ever claimed twice — the same correctness guarantee, lock-free.
 */
final class OptimisticClaimDriver implements ClaimDriver
{
    public function __construct(private readonly StateMachine $stateMachine)
    {
    }

    public function name(): string
    {
        return 'optimistic';
    }

    public function claim(WorkerContext $worker, int $want): array
    {
        if ($want < 1) {
            return [];
        }

        $conn = $this->connection();

        // Over-select candidates (un-locked): some may be claimed out from under
        // us by a peer before we get to them — that's fine, we just skip those.
        $candidates = $conn->table($this->tbl('jobs'))
            ->where('lane', $worker->lane)
            ->where('state', JobState::Queued->value)
            ->where(function ($q) use ($conn): void {
                $q->whereNull('available_at')
                    ->orWhere('available_at', '<=', $conn->raw('CURRENT_TIMESTAMP'));
            })
            ->orderByDesc('priority')
            // Age within priority (see SkipLockedClaimDriver): a requeued orphan/
            // retry keeps its original created_at and claims ahead of fresher work.
            ->orderBy('created_at')
            ->limit($want * 4)
            ->pluck('id');

        $claimed = [];
        foreach ($candidates as $id) {
            if (count($claimed) >= $want) {
                break;
            }

            try {
                $claimed[] = $conn->transaction(fn (): Claimed => $this->mintAttempt($conn, (string) $id, $worker));
            } catch (IllegalTransitionException|GuardFailedException|StaleFencingTokenException) {
                // Lost the race for this row (a peer claimed it first) — skip it.
            }
        }

        return $claimed;
    }

    private function mintAttempt(Connection $conn, string $jobId, WorkerContext $worker): Claimed
    {
        /** @var Job $job */
        $job = Job::query()->findOrFail($jobId);

        $attemptNumber = (int) $job->attempt_count + 1;
        $fencingToken = $attemptNumber;
        $attemptId = (string) Uuid::v7();

        // 1. The guarded transition IS the claim. If a peer already moved this row
        //    out of 'queued', the guarded UPDATE matches nothing and this throws —
        //    rolling the whole attempt back so no stamp is left behind.
        $this->stateMachine->applyJobTransition(
            $job,
            JobState::Running,
            TransitionContext::for(ActorType::Supervisor, $worker->workerId, 'claimed')
                ->withProcessSnapshot($worker->snapshot())
                ->withContext(['attempt_id' => $attemptId, 'fencing_token' => $fencingToken])
        );

        // 2. We won — write the phase-1 stamp and point the job at it.
        $conn->table($this->tbl('job_attempts'))->insert([
            'id' => $attemptId,
            'job_id' => $jobId,
            'attempt_number' => $attemptNumber,
            'state' => AttemptState::Dispatched->value,
            'fencing_token' => $fencingToken,
            'worker_id' => $worker->workerId,
            'host_id' => $worker->hostId,
            'hostname' => $worker->hostname,
            'supervisor_pid' => $worker->supervisorPid,
            'supervisor_start_time' => $worker->supervisorStartTime,
            'created_at' => $conn->raw('CURRENT_TIMESTAMP'),
            'updated_at' => $conn->raw('CURRENT_TIMESTAMP'),
        ]);

        $conn->table($this->tbl('jobs'))->where('id', $jobId)->update([
            'attempt_count' => $attemptNumber,
            'current_attempt_id' => $attemptId,
            'updated_at' => $conn->raw('CURRENT_TIMESTAMP'),
        ]);

        return new Claimed($jobId, $attemptId, $attemptNumber, $fencingToken);
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
