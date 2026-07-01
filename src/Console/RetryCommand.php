<?php

declare(strict_types=1);

namespace JobWarden\Console;

use JobWarden\Console\Concerns\ResolvesJob;
use JobWarden\Operations\OperatorActions;
use JobWarden\States\JobState;
use Illuminate\Console\Command;

final class RetryCommand extends Command
{
    use ResolvesJob;

    protected $signature = 'jobwarden:retry {job : id or prefix} {--reason=operator retry} {--force : retry a non-idempotent job}';

    protected $description = 'Retry a failed job (mint a new attempt).';

    public function handle(OperatorActions $ops): int
    {
        $job = $this->resolveJob((string) $this->argument('job'));
        if ($job === null) {
            $this->error('job not found');

            return self::FAILURE;
        }

        if ($job->state !== JobState::Failed) {
            $this->error("job is {$job->state->value}, not failed");

            return self::FAILURE;
        }

        if (! $job->idempotent && ! $this->option('force')) {
            $this->warn('job is NOT idempotent — re-running may double its side effects. Pass --force to proceed.');

            return self::FAILURE;
        }

        $ops->retry($job, (string) $this->option('reason'), 'cli');
        $this->info("retrying {$job->id} → queued");

        return self::SUCCESS;
    }
}
