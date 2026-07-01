<?php

declare(strict_types=1);

namespace JobWarden\Console;

use JobWarden\Console\Concerns\ResolvesJob;
use JobWarden\Operations\OperatorActions;
use Illuminate\Console\Command;

final class StopCommand extends Command
{
    use ResolvesJob;

    protected $signature = 'jobwarden:stop {job : id or prefix} {--reason=operator stop}';

    protected $description = 'Stop a running job (graceful then forced).';

    public function handle(OperatorActions $ops): int
    {
        $job = $this->resolveJob((string) $this->argument('job'));
        if ($job === null) {
            $this->error('job not found');

            return self::FAILURE;
        }

        $ops->stop($job, (string) $this->option('reason'), 'cli');
        $this->info("stop requested for {$job->id} (was {$job->state->value})");

        return self::SUCCESS;
    }
}
