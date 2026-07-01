<?php

declare(strict_types=1);

namespace JobWarden\StateMachine\Support;

use JobWarden\States\JobState;

/**
 * Maps each Job state to the batch progress counter it falls under, partitioning
 * all states into five buckets so that
 *   pending_count + running_count + succeeded_count + failed_count + canceled_count
 * always equals total_jobs (a testable invariant). Refined further by the batch
 * coordinator in P12.
 */
final class JobStateBuckets
{
    public static function column(JobState $state): string
    {
        return match ($state) {
            JobState::Pending, JobState::Queued, JobState::Retrying => 'pending_count',
            JobState::Running, JobState::Orphaned => 'running_count',
            JobState::Succeeded => 'succeeded_count',
            JobState::Failed => 'failed_count',
            JobState::Canceled, JobState::Stopped => 'canceled_count',
        };
    }
}
