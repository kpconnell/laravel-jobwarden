<?php

declare(strict_types=1);

namespace JobWarden\Supervisor;

use Illuminate\Support\Facades\Log;

/**
 * The local reaper that `jobwarden:work` spawns and supervises as a SEPARATE child
 * process, so a worker can never run without Tier-2 recovery and nobody has to
 * remember to start `jobwarden:reap:local`.
 *
 * It has to be a distinct process (not inline) precisely so it OUTLIVES a
 * supervisor crash: on a clean drain the worker stops it; on a crash the worker
 * process dies and this child reparents to init and keeps reaping — detecting the
 * dead supervisor and cleaning up its children — until the platform restarts the
 * worker. A per-host lease (see LocalReaper) elects a single active reaper, so two
 * workers on one host (e.g. jobwarden:work + jobwarden:scheduled-worker) each
 * spawning one is harmless: one scans, the rest idle as hot spares.
 */
final class CoReaper
{
    private const SIGTERM = 15;

    private const SIGKILL = 9;

    /** @var resource|null */
    private $process = null;

    private bool $stopping = false;

    /** @param string[] $command */
    public function __construct(
        private readonly array $command,
        private readonly string $cwd,
    ) {
    }

    public function start(): void
    {
        $this->stopping = false;
        $this->spawn();
    }

    /** Respawn the reaper if it exited — called each supervisor tick. */
    public function ensureAlive(): void
    {
        if ($this->stopping || $this->process === null) {
            return;
        }

        if (! $this->isRunning()) {
            proc_close($this->process);
            $this->process = null;
            Log::warning('jobwarden:work co-reaper exited — respawning');
            $this->spawn();
        }
    }

    public function running(): bool
    {
        return $this->process !== null && $this->isRunning();
    }

    /**
     * Graceful stop (a clean worker drain): SIGTERM, grace, SIGKILL, close. A
     * worker *crash* never reaches here — the child then reparents to init and
     * keeps reaping, which is the whole point.
     */
    public function stop(int $graceSeconds = 10): void
    {
        $this->stopping = true;
        if ($this->process === null) {
            return;
        }

        if ($this->isRunning()) {
            proc_terminate($this->process, self::SIGTERM);
            $deadline = microtime(true) + max(1, $graceSeconds);
            while (microtime(true) < $deadline && $this->isRunning()) {
                usleep(100_000);
            }
            if ($this->isRunning()) {
                proc_terminate($this->process, self::SIGKILL);
            }
        }

        proc_close($this->process);
        $this->process = null;
    }

    private function isRunning(): bool
    {
        return $this->process !== null && (proc_get_status($this->process)['running'] ?? false);
    }

    private function spawn(): void
    {
        // Inherit the worker's stdout/stderr so the reaper's structured logs land on
        // the same stream (docker logs / journald) — never redirected to a file.
        $out = defined('STDOUT') ? STDOUT : ['file', 'php://stdout', 'w'];
        $err = defined('STDERR') ? STDERR : ['file', 'php://stderr', 'w'];
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => $out, 2 => $err];

        $process = @proc_open($this->command, $descriptors, $pipes, $this->cwd);
        $this->process = is_resource($process) ? $process : null;

        if ($this->process === null) {
            Log::error('jobwarden:work could not spawn its co-reaper', ['command' => $this->command]);
        }
    }
}
