<?php

declare(strict_types=1);

namespace JobWarden\Jobs;

use JobWarden\Contracts\JobWardenJob;
use JobWarden\Runner\JobContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Built-in handler that runs a Laravel artisan command as a JobWarden unit of
 * work (spec §7 extension). The scheduler emits these into the `scheduled` lane;
 * the dedicated runner executes them in a child process, so a command gets the
 * full machinery — `/proc` stamp, reaper recovery, the live log tail.
 *
 * Params: { "command": "cache:prune", "arguments": {"--force": true} }.
 * The command's console output is captured into the job log (observability); a
 * non-zero exit code throws, so the run is recorded failed with its output.
 *
 * A command is NOT a job: idempotency does not apply. Recovery is the schedule's
 * concern (the next occurrence), so these run single-shot (max_attempts=1).
 */
final class RunArtisanCommand implements JobWardenJob
{
    public function __construct(
        private readonly string $command,
        private readonly array $arguments = [],
    ) {
    }

    public function handle(JobContext $context): void
    {
        // A missing 'command' key already failed loud at constructor binding;
        // this guards an explicitly-empty one.
        if ($this->command === '') {
            throw new RuntimeException('RunArtisanCommand requires a non-empty "command" param.');
        }

        Log::info("running artisan command: {$this->command}", ['step' => 'command', 'arguments' => $this->arguments]);

        $output = new BufferedOutput;
        $exitCode = Artisan::call($this->command, $this->arguments, $output);

        // Surface the command's own output into the job log, line by line, so it
        // shows up in `jobwarden:logs` instead of being swallowed.
        foreach (preg_split('/\R/', trim($output->fetch())) ?: [] as $line) {
            if ($line !== '') {
                Log::info($line, ['step' => 'command:output']);
            }
        }

        if ($exitCode !== 0) {
            throw new RuntimeException("artisan command [{$this->command}] exited with code {$exitCode}");
        }

        Log::info("artisan command [{$this->command}] succeeded", ['step' => 'command']);
    }

    public function idempotent(): bool
    {
        // Vestigial: the engine keys recovery off the job.idempotent column, set
        // per-schedule. A command run is single-shot regardless.
        return false;
    }
}
