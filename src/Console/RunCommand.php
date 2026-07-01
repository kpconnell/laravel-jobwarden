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
        // The child's structured Log:: output goes to job_logs (via the capture
        // bridge) only — NOT to the process stdout/stderr. That keeps the
        // per-attempt stdout/stderr file holding ONLY raw output (a fatal/OOM's
        // dying words), which the supervisor drains into job_logs on reap. So
        // everything ends up queryable in the DB; nobody hunts for files.
        config([
            'logging.default' => 'jobwarden_child',
            'logging.channels.jobwarden_child' => ['driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class],
        ]);

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
