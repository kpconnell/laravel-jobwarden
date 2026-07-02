<?php

declare(strict_types=1);

namespace JobWarden\Console;

use JobWarden\Console\Concerns\ResolvesJob;
use JobWarden\Operations\OperatorActions;
use JobWarden\States\JobState;
use Illuminate\Console\Command;

final class RestartCommand extends Command
{
    use ResolvesJob;

    protected $signature = 'jobwarden:restart {job : id or prefix} {--reason=operator restart} {--force : restart a non-idempotent job}';

    protected $description = 'Restart a parked orphaned or stopped job (explicit, audited override).';

    public function handle(OperatorActions $ops): int
    {
        $job = $this->resolveJob((string) $this->argument('job'));
        if ($job === null) {
            $this->error('job not found');

            return self::FAILURE;
        }

        if (! in_array($job->state, [JobState::Orphaned, JobState::Stopped], true)) {
            $this->error("job is {$job->state->value}, not orphaned or stopped");

            return self::FAILURE;
        }

        if (! $job->idempotent && ! $this->option('force')) {
            $this->warn('job is NOT idempotent — re-running may double side effects. Pass --force.');

            return self::FAILURE;
        }

        $ops->restart($job, (string) $this->option('reason'), 'cli');
        $this->info("restarting {$job->id} → queued");

        return self::SUCCESS;
    }
}
