<?php

declare(strict_types=1);

namespace JobWarden\Console;

use Illuminate\Console\Command;

/**
 * The dedicated runner for the scheduled tier — a supervisor bound to the
 * `scheduled` lane. Deploy it as its OWN top-level process, separate from the
 * business `jobwarden:work` fleet, so a wedged supervisor or a saturated worker
 * fleet can never starve or block scheduled work. It's just `jobwarden:work
 * --lane=scheduled` under an obvious name.
 */
final class ScheduledWorkerCommand extends Command
{
    protected $signature = 'jobwarden:scheduled-worker
        {--capacity= : max concurrent scheduled runs}
        {--drain-timeout= : seconds to wait for in-flight runs on SIGTERM}
        {--once : run a single tick and exit}';

    protected $description = 'Run the dedicated runner for the scheduled tier (the "scheduled" lane).';

    public function handle(): int
    {
        $args = ['--lane' => 'scheduled'];

        if ($this->option('capacity') !== null) {
            $args['--capacity'] = $this->option('capacity');
        }
        if ($this->option('drain-timeout') !== null) {
            $args['--drain-timeout'] = $this->option('drain-timeout');
        }
        if ($this->option('once')) {
            $args['--once'] = true;
        }

        return $this->call('jobwarden:work', $args);
    }
}
