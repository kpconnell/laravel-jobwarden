<?php

declare(strict_types=1);

namespace JobWarden\StateMachine;

use JobWarden\Events\AttemptStateChanged;
use JobWarden\Events\JobStateChanged;
use JobWarden\Models\Job;
use JobWarden\Models\JobAttempt;
use JobWarden\StateMachine\Contracts\TransitionTable;
use JobWarden\StateMachine\Exceptions\GuardFailedException;
use JobWarden\StateMachine\Exceptions\IllegalTransitionException;
use JobWarden\StateMachine\Exceptions\StaleFencingTokenException;
use JobWarden\StateMachine\Support\JobStateBuckets;
use JobWarden\StateMachine\Tables\AttemptTransitions;
use JobWarden\StateMachine\Tables\JobTransitions;
use JobWarden\States\ActorType;
use JobWarden\States\JobState;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY path that mutates jobs.state / job_attempts.state (spec §11).
 *
 * For every transition it, in ONE transaction on the dedicated connection:
 *  1. validates the edge + actor + decided-input guards (illegal ⇒ throw);
 *  2. moves state via a guarded UPDATE whose affected-rows count is the proof of
 *     ownership (fencing token + "still in `from` state" live in the WHERE) —
 *     affected==0 ⇒ reject + record a reconciliation event, never clobber;
 *  3. inserts the append-only job_events row (with process snapshot + token);
 *  4. adjusts batch counters via atomic SQL increments;
 *  5. dispatches a Laravel event AFTER commit.
 */
class StateMachine
{
    public function __construct(
        private readonly JobTransitions $jobTransitions = new JobTransitions,
        private readonly AttemptTransitions $attemptTransitions = new AttemptTransitions,
    ) {
    }

    public function applyJobTransition(Job $job, JobState $to, TransitionContext $context): TransitionResult
    {
        return $this->transition($job, $this->jobTransitions, $to, $context);
    }

    public function applyAttemptTransition(JobAttempt $attempt, \JobWarden\States\AttemptState $to, TransitionContext $context): TransitionResult
    {
        return $this->transition($attempt, $this->attemptTransitions, $to, $context);
    }

    private function transition(Model $entity, TransitionTable $table, \BackedEnum $to, TransitionContext $ctx): TransitionResult
    {
        $level = $table->level();
        $isAttempt = $level === 'attempt';

        $current = $entity->getAttribute('state');
        $fromValue = $current instanceof \BackedEnum ? $current->value : (string) $current;
        $toValue = $to->value;

        // 1. Validate edge, actor, fencing requirement, decided-input guards.
        $transition = $table->find($fromValue, $toValue);
        if ($transition === null) {
            throw new IllegalTransitionException($level, $fromValue, $toValue);
        }
        if (! $transition->allowsActor($ctx->actorType)) {
            throw new IllegalTransitionException($level, $fromValue, $toValue, "actor '{$ctx->actorType->value}' not permitted");
        }
        if ($isAttempt && $transition->requiresFencingToken && $ctx->expectedFencingToken === null) {
            throw new \LogicException("Attempt transition {$fromValue} → {$toValue} requires the current fencing token.");
        }
        foreach ($transition->guards as $guard) {
            if (! $guard->passes($entity, $ctx)) {
                throw new GuardFailedException($guard->reason(), $fromValue, $toValue);
            }
        }

        $conn = $this->connection();
        $conn->beginTransaction();

        try {
            // 2. The guarded UPDATE — the heart.
            $set = $this->timingColumns($conn, $level, $to);
            $set['state'] = $toValue;
            $set['updated_at'] = $conn->raw('CURRENT_TIMESTAMP');
            if ($isAttempt && $ctx->fencingBump) {
                $set['fencing_token'] = $conn->raw('fencing_token + 1');
            }

            $query = $conn->table($entity->getTable())
                ->where($entity->getKeyName(), $entity->getKey())
                ->where('state', $fromValue);

            if ($isAttempt && $ctx->expectedFencingToken !== null) {
                $query->where('fencing_token', $ctx->expectedFencingToken);
            }

            $affected = $query->update($set);

            if ($affected === 0) {
                // Lost the race: stale state or stale epoch. Reject without
                // clobbering, and record a reconciliation event instead.
                $conn->rollBack();
                $this->recordReconciliation($entity, $level, $fromValue, $toValue, $ctx);

                throw new StaleFencingTokenException($level, $fromValue, $toValue, $ctx->expectedFencingToken);
            }

            // New epoch value (attempts only): authoritative re-read after a bump.
            $newToken = null;
            if ($isAttempt) {
                $newToken = $ctx->fencingBump
                    ? (int) $conn->table($entity->getTable())->where($entity->getKeyName(), $entity->getKey())->value('fencing_token')
                    : ($ctx->expectedFencingToken ?? (int) $entity->getAttribute('fencing_token'));
            }

            // 3. Append-only audit row, same transaction.
            $eventId = $this->writeEvent($conn, $entity, $level, $fromValue, $toValue, $ctx, $newToken);

            // 4. Batch progress counters (jobs only), atomic SQL increments.
            if (! $isAttempt) {
                $this->adjustBatchCounters($conn, $entity, $fromValue, $toValue);
            }

            // 5. Reflect the new state in-memory, then queue the post-commit event.
            $entity->setAttribute('state', $to);
            if ($isAttempt && $newToken !== null) {
                $entity->setAttribute('fencing_token', $newToken);
            }
            $entity->syncOriginal();

            $event = $this->makeEvent($entity, $level, $current, $to, $ctx, $eventId);
            $conn->afterCommit(static fn () => event($event));

            $conn->commit();
        } catch (StaleFencingTokenException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            throw $e;
        }

        // Reconstruct the true `from` enum. In practice `$current` is already an
        // enum (models cast `state`), but a raw/partially-hydrated entity yields a
        // string — rebuild from the level's enum rather than mislabelling it `$to`.
        $fromEnum = $current instanceof \BackedEnum
            ? $current
            : ($isAttempt ? \JobWarden\States\AttemptState::from($fromValue) : JobState::from($fromValue));

        return new TransitionResult($fromEnum, $to, $eventId, $newToken);
    }

    /** @return array<string, mixed> */
    private function timingColumns(Connection $conn, string $level, \BackedEnum $to): array
    {
        $now = $conn->raw('CURRENT_TIMESTAMP');
        $set = [];

        // Job-only: stamp queued_at on admission.
        if ($level === 'job' && $to->value === 'queued') {
            $set['queued_at'] = $conn->raw('coalesce(queued_at, CURRENT_TIMESTAMP)');
        }

        if ($to->value === 'running') {
            $set['started_at'] = $conn->raw('coalesce(started_at, CURRENT_TIMESTAMP)');
        }

        if (in_array($to->value, ['succeeded', 'failed', 'canceled', 'stopped'], true)) {
            $set['finished_at'] = $now;
        }

        return $set;
    }

    private function writeEvent(Connection $conn, Model $entity, string $level, string $from, string $to, TransitionContext $ctx, ?int $fencingToken): int
    {
        [$jobId, $attemptId] = $this->identity($entity, $level);

        return (int) $conn->table($this->tbl('job_events'))->insertGetId([
            'job_id' => $jobId,
            'attempt_id' => $attemptId,
            'level' => $level,
            'from_state' => $from,
            'to_state' => $to,
            'actor_type' => $ctx->actorType->value,
            'actor_id' => $ctx->actorId,
            'reason' => $ctx->reason,
            'context' => $this->encodeContext($ctx->eventContext($fencingToken)),
            'created_at' => $conn->raw('CURRENT_TIMESTAMP'),
        ]);
    }

    private function recordReconciliation(Model $entity, string $level, string $from, string $to, TransitionContext $ctx): void
    {
        $conn = $this->connection();
        [$jobId, $attemptId] = $this->identity($entity, $level);

        $conn->table($this->tbl('job_events'))->insert([
            'job_id' => $jobId,
            'attempt_id' => $attemptId,
            'level' => $level,
            'from_state' => $from,
            'to_state' => $from, // no state change occurred
            'actor_type' => ActorType::System->value,
            'reason' => "stale_write_rejected: attempted {$from} → {$to}",
            'context' => $this->encodeContext(array_merge($ctx->eventContext(null), [
                'rejected' => true,
                'attempted_to' => $to,
                'expected_fencing_token' => $ctx->expectedFencingToken,
                'by_actor' => $ctx->actorType->value,
            ])),
            'created_at' => $conn->raw('CURRENT_TIMESTAMP'),
        ]);
    }

    private function adjustBatchCounters(Connection $conn, Model $job, string $from, string $to): void
    {
        $batchId = $job->getAttribute('batch_id');
        if ($batchId === null) {
            return;
        }

        $fromCol = JobStateBuckets::column(JobState::from($from));
        $toCol = JobStateBuckets::column(JobState::from($to));
        if ($fromCol === $toCol) {
            return;
        }

        $conn->table($this->tbl('batches'))->where('id', $batchId)->update([
            $fromCol => $conn->raw("{$fromCol} - 1"),
            $toCol => $conn->raw("{$toCol} + 1"),
        ]);
    }

    private function makeEvent(Model $entity, string $level, \BackedEnum|string $from, \BackedEnum $to, TransitionContext $ctx, int $eventId): object
    {
        if ($level === 'attempt') {
            /** @var JobAttempt $entity */
            return new AttemptStateChanged(
                $entity,
                $from instanceof \JobWarden\States\AttemptState ? $from : \JobWarden\States\AttemptState::from((string) $from),
                $to,
                $ctx,
                $eventId,
            );
        }

        /** @var Job $entity */
        return new JobStateChanged(
            $entity,
            $from instanceof JobState ? $from : JobState::from((string) $from),
            $to,
            $ctx,
            $eventId,
        );
    }

    /** @return array{0:mixed,1:?string} [job_id, attempt_id] */
    private function identity(Model $entity, string $level): array
    {
        if ($level === 'attempt') {
            return [$entity->getAttribute('job_id'), $entity->getKey()];
        }

        return [$entity->getKey(), null];
    }

    private function encodeContext(array $context): ?string
    {
        return $context === [] ? null : json_encode($context, JSON_THROW_ON_ERROR);
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
