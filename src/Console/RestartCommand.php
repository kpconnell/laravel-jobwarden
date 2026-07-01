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

    protected $signature = 'jobwarden:restart {job : id or prefix} {--reason=operator restart} {--force : restart a non-idempotent parked orphan}';

    protected $description = 'Restart a parked orphaned job (explicit, audited override).';

    public function handle(OperatorActions $ops): int
    {
        $job = $this->resolveJob((string) $this->argument('job'));
        if ($job === null) {
            $this->error('job not found');

            return self::FAILURE;
        }

        if ($job->state !== JobState::Orphaned) {
            $this->error("job is {$job->state->value}, not orphaned");

            return self::FAILURE;
        }

        if (! $job->idempotent && ! $this->option('force')) {
            $this->warn('job is NOT idempotent and the prior attempt is indeterminate — re-running may double side effects. Pass --force.');

            return self::FAILURE;
        }

        $ops->restart($job, (string) $this->option('reason'), 'cli');
        $this->info("restarting {$job->id} → queued");

        return self::SUCCESS;
    }
}
