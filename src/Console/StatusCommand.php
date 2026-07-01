<?php

declare(strict_types=1);

namespace JobWarden\Console;

use JobWarden\Models\Job;
use JobWarden\Models\JobAttempt;
use Illuminate\Console\Command;

/**
 * Operator inspection (spec §9): answer "what is happening" by querying the
 * durable tables — current state, the owning process, exit/signal.
 */
final class StatusCommand extends Command
{
    protected $signature = 'jobwarden:status {--limit=20 : rows to show} {--state= : filter by job state}';

    protected $description = 'Show recent JobWarden jobs and their current attempt.';

    public function handle(): int
    {
        $query = Job::query()->orderByDesc('created_at')->limit((int) $this->option('limit'));
        if ($state = $this->option('state')) {
            $query->where('state', $state);
        }

        $rows = $query->get()->map(function (Job $job): array {
            $attempt = $job->current_attempt_id ? JobAttempt::find($job->current_attempt_id) : null;

            return [
                substr((string) $job->id, 0, 8),
                class_basename($job->job_class),
                $job->state->value,
                $job->attempt_count.'/'.$job->max_attempts,
                $attempt?->host_id ? substr($attempt->host_id, 0, 6) : '-',
                $attempt?->child_pid ?? '-',
                $attempt?->state->value ?? '-',
                $attempt?->exit_code ?? '-',
                $attempt?->term_signal ?? '-',
            ];
        })->all();

        $this->table(['job', 'class', 'job.state', 'att', 'host', 'pid', 'att.state', 'exit', 'sig'], $rows);

        return self::SUCCESS;
    }
}
