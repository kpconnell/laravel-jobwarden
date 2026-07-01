<?php

declare(strict_types=1);

namespace JobWarden\Claim;

use JobWarden\Claim\Contracts\ClaimDriver;
use JobWarden\Models\Job;
use JobWarden\StateMachine\StateMachine;
use JobWarden\StateMachine\TransitionContext;
use JobWarden\States\ActorType;
use JobWarden\States\JobState;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\Uuid;

/**
 * The preferred claim driver (spec §5.1): one transaction does
 *   SELECT id … WHERE state='queued' … LIMIT n FOR UPDATE SKIP LOCKED
 * so two supervisors never claim the same row — the second simply skips it.
 * For each locked row it writes the phase-1 stamp (a dispatched attempt) and
 * moves the job queued → running through the StateMachine.
 */
final class SkipLockedClaimDriver implements ClaimDriver
{
    public function __construct(private readonly StateMachine $stateMachine)
    {
    }

    public function name(): string
    {
        return 'skip_locked';
    }

    public function claim(WorkerContext $worker, int $want): array
    {
        if ($want < 1) {
            return [];
        }

        $conn = $this->connection();
        $claimed = [];

        $conn->transaction(function () use ($conn, $worker, $want, &$claimed): void {
            $ids = $conn->table($this->tbl('jobs'))
                ->where('lane', $worker->lane)
                ->where('state', JobState::Queued->value)
                ->where(function ($q) use ($conn): void {
                    $q->whereNull('available_at')
                        ->orWhere('available_at', '<=', $conn->raw('CURRENT_TIMESTAMP'));
                })
                ->orderByDesc('priority')
                ->orderBy('available_at')
                ->limit($want)
                ->lock('for update skip locked')
                ->pluck('id');

            foreach ($ids as $id) {
                $claimed[] = $this->mintAttempt($conn, (string) $id, $worker);
            }
        });

        return $claimed;
    }

    private function mintAttempt(Connection $conn, string $jobId, WorkerContext $worker): Claimed
    {
        /** @var Job $job */
        $job = Job::query()->findOrFail($jobId);

        $attemptNumber = (int) $job->attempt_count + 1;
        $fencingToken = $attemptNumber; // fencing epoch = attempt number
        $attemptId = (string) Uuid::v7();

        // PHASE-1 stamp: supervisor identity at claim. child_* completed in P5.
        $conn->table($this->tbl('job_attempts'))->insert([
            'id' => $attemptId,
            'job_id' => $jobId,
            'attempt_number' => $attemptNumber,
            'state' => \JobWarden\States\AttemptState::Dispatched->value,
            'fencing_token' => $fencingToken,
            'worker_id' => $worker->workerId,
            'host_id' => $worker->hostId,
            'hostname' => $worker->hostname,
            'supervisor_pid' => $worker->supervisorPid,
            'supervisor_start_time' => $worker->supervisorStartTime,
            'created_at' => $conn->raw('CURRENT_TIMESTAMP'),
            'updated_at' => $conn->raw('CURRENT_TIMESTAMP'),
        ]);

        // Bump attempt_count + point current_attempt_id (not a state change, so
        // it goes via the query builder, same transaction).
        $conn->table($this->tbl('jobs'))->where('id', $jobId)->update([
            'attempt_count' => $attemptNumber,
            'current_attempt_id' => $attemptId,
            'updated_at' => $conn->raw('CURRENT_TIMESTAMP'),
        ]);

        // The state move (queued → running) + audit event, via the one mutator.
        $this->stateMachine->applyJobTransition(
            $job,
            JobState::Running,
            TransitionContext::for(ActorType::Supervisor, $worker->workerId, 'claimed')
                ->withProcessSnapshot($worker->snapshot())
                ->withContext(['attempt_id' => $attemptId, 'fencing_token' => $fencingToken])
        );

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
