<?php

declare(strict_types=1);

namespace JobWarden\Console;

use JobWarden\Console\Concerns\ResolvesJob;
use JobWarden\Operations\OperatorActions;
use Illuminate\Console\Command;

/**
 * Operator actions (spec §10.1/§10.2): cancel / stop / retry / restart. Each is a
 * durable, audited transition (actor=operator) recorded in job_events.
 */
final class CancelCommand extends Command
{
    use ResolvesJob;

    protected $signature = 'jobwarden:cancel {job : id or prefix} {--reason=operator cancel}';

    protected $description = 'Cancel a job (pre-run) or stop it if running.';

    public function handle(OperatorActions $ops): int
    {
        $job = $this->resolveJob((string) $this->argument('job'));
        if ($job === null) {
            $this->error('job not found');

            return self::FAILURE;
        }

        $ops->cancel($job, (string) $this->option('reason'), 'cli');
        $this->info("cancel requested for {$job->id} (was {$job->state->value} → ".$job->fresh()->state->value.')');

        return self::SUCCESS;
    }
}
