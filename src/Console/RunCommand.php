<?php

declare(strict_types=1);

namespace JobWarden\Console;

use JobWarden\Runner\ChildRunner;
use JobWarden\Runner\ExitCode;
use Illuminate\Console\Command;

/**
 * Internal job-runner child (spec §6.1, §10.2). Not called directly — the
 * supervisor spawns it: `jobwarden:run {attempt} --token --nonce`.
 */
final class RunCommand extends Command
{
    protected $signature = 'jobwarden:run {attempt : the attempt id} {--token= : the fencing token} {--nonce= : the process nonce}';

    protected $description = 'Internal: execute a single job attempt in a dedicated child process.';

    public function handle(ChildRunner $runner): int
    {
        // NB: the child's log channel is swapped to the job_logs-only sink by
        // ChildRunner itself — it has to hold for the prefork mode too, which never
        // reaches this command.
        $attemptId = (string) $this->argument('attempt');
        $token = (int) $this->option('token');
        $nonce = (string) $this->option('nonce');

        if ($attemptId === '' || $token < 1 || $nonce === '') {
            $this->error('jobwarden:run requires {attempt} --token --nonce');

            return ExitCode::STALE_TOKEN;
        }

        return $runner->run($attemptId, $token, $nonce);
    }
}
