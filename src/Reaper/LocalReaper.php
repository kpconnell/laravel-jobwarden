<?php

declare(strict_types=1);

namespace JobWarden\Reaper;

use JobWarden\Models\JobAttempt;
use JobWarden\Models\Worker;
use JobWarden\Process\Contracts\HostIdentity;
use JobWarden\Process\Contracts\ProcessProbe;
use JobWarden\Process\ProcessStamp;
use JobWarden\States\AttemptState;
use JobWarden\Worker\WorkerRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Tier 2 (spec §5.4) — a SEPARATE per-host process (not a supervisor, so it can
 * detect a *dead supervisor* whose children reparented to init). While the host
 * is alive its local reaper is authoritative: it verifies stamps directly. Its
 * own workers row IS the host lease the global reaper watches.
 *
 * The dangerous case it exists for: supervisor gone, child still alive. v1
 * policy — SIGTERM → SIGKILL the stamped child, confirm it is dead, and only
 * THEN orphan the attempt. It never mints a replacement while the old child
 * breathes (that is what would cause duplicate side effects).
 */
final class LocalReaper
{
    private const SIGTERM = 15;

    private const SIGKILL = 9;

    private ?Worker $worker = null;

    private string $hostId = '';

    private string $reaperId = '';

    private float $lastLeaseOk = 0.0;

    private bool $fenced = false;

    public function __construct(
        private readonly StampVerifier $verifier,
        private readonly ProcessProbe $probe,
        private readonly AttemptOrphaner $orphaner,
        private readonly WorkerRegistry $registry,
        private readonly HostIdentity $host,
    ) {
    }

    public function boot(): Worker
    {
        if ($this->worker !== null) {
            return $this->worker;
        }

        $this->hostId = $this->host->hostId();
        $this->worker = $this->registry->register('local_reaper');
        $this->reaperId = (string) $this->worker->id;
        $this->lastLeaseOk = microtime(true);

        Log::info('jobwarden local reaper started', [
            'role' => 'local_reaper',
            'worker_id' => $this->reaperId,
            'host_id' => $this->hostId,
            'pid' => getmypid(),
        ]);

        return $this->worker;
    }

    public function hostId(): string
    {
        return $this->hostId;
    }

    public function fenced(): bool
    {
        return $this->fenced;
    }

    /** One scan. Returns false if it self-fenced (the caller should exit). */
    public function tick(?float $now = null): bool
    {
        if ($this->worker === null) {
            $this->boot();
        }
        $now ??= microtime(true);

        $this->refreshLease($now);

        if (($now - $this->lastLeaseOk) > (float) config('jobwarden.reaper.self_fence_ttl', 25)) {
            $this->selfFence();

            return false;
        }

        $this->scan();

        return true;
    }

    public function scan(): void
    {
        foreach ($this->inFlightAttempts() as $attempt) {
            $verdict = $this->verifier->verdict($attempt);

            if ($verdict->isHealthy()) {
                continue; // the supervisor owns it (Tier 1 handles child death)
            }

            // Supervisor is gone.
            if ($verdict->isReparented()) {
                $this->killReparentedThenOrphan($attempt, $verdict->stamp);

                continue;
            }

            // Supervisor and child both gone (or never stamped) — execution over.
            $this->orphaner->orphan($attempt, $this->reaperId, $this->hostId, 'local', 'supervisor and child gone');
        }
    }

    private function killReparentedThenOrphan(JobAttempt $attempt, ProcessStamp $stamp): void
    {
        $grace = (int) config('jobwarden.supervisor.graceful_timeout', 10);

        $dead = $this->killAndConfirm((int) $stamp->childPid, $stamp->childStartTime, $grace);
        if (! $dead) {
            Log::error('local reaper: could not kill reparented child; will retry next scan', [
                'attempt' => (string) $attempt->id, 'child_pid' => $stamp->childPid, 'host_id' => $this->hostId,
            ]);

            return; // do NOT orphan while the child may still be running
        }

        $this->orphaner->orphan($attempt, $this->reaperId, $this->hostId, 'local', 'supervisor gone; reparented child SIGKILLed');
    }

    /**
     * SIGTERM, grace, SIGKILL, confirm dead — re-verifying start-time before each
     * signal so a recycled pid (a different process now) is never killed.
     */
    private function killAndConfirm(int $childPid, ?string $startTime, int $graceSeconds): bool
    {
        if (! $this->probe->matches($childPid, $startTime)) {
            return true; // already gone
        }

        $this->probe->signal($childPid, self::SIGTERM);

        $deadline = microtime(true) + max(1, $graceSeconds);
        while (microtime(true) < $deadline) {
            if (! $this->probe->matches($childPid, $startTime)) {
                return true;
            }
            usleep(100_000);
        }

        if ($this->probe->matches($childPid, $startTime)) {
            $this->probe->signal($childPid, self::SIGKILL);
        }

        $deadline = microtime(true) + 3;
        while (microtime(true) < $deadline) {
            if (! $this->probe->matches($childPid, $startTime)) {
                return true;
            }
            usleep(50_000);
        }

        return ! $this->probe->matches($childPid, $startTime);
    }

    /**
     * Lost connectivity (partition): assume Tier 3 has already reaped us, so
     * SIGKILL every stamped child on this host to bound the duplicate
     * side-effect window, then signal the caller to exit (systemd restarts us).
     */
    public function selfFence(): void
    {
        Log::critical('local reaper: SELF-FENCING (lost host lease) — killing stamped children', [
            'host_id' => $this->hostId, 'reaper_id' => $this->reaperId,
        ]);

        foreach ($this->inFlightAttempts() as $attempt) {
            $stamp = ProcessStamp::fromAttempt($attempt);
            if ($stamp->hasChild() && $this->probe->matches((int) $stamp->childPid, $stamp->childStartTime)) {
                $this->probe->signal((int) $stamp->childPid, self::SIGKILL);
            }
        }

        $this->fenced = true;
    }

    private function refreshLease(float $now): void
    {
        try {
            $this->registry->heartbeat($this->worker);
            $this->lastLeaseOk = $now;
        } catch (\Throwable $e) {
            Log::warning('local reaper: failed to refresh host lease', ['error' => $e->getMessage()]);
        }
    }

    /** @return \Illuminate\Support\Collection<int,JobAttempt> */
    private function inFlightAttempts()
    {
        return JobAttempt::query()
            ->where('host_id', $this->hostId)
            ->whereIn('state', [AttemptState::Dispatched->value, AttemptState::Running->value])
            ->get();
    }
}
