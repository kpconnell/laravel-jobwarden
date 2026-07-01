<?php

declare(strict_types=1);

namespace JobWarden\Process\Fake;

use JobWarden\Process\Contracts\ProcessProbe;
use JobWarden\Process\ProcessStamp;
use JobWarden\Process\VerifyResult;

/**
 * In-memory probe — the lever that makes reaper tests deterministic without real
 * host death. Tests register processes (alive, with a start-time / ppid / nonce)
 * and can kill them or assert which signals were sent.
 *
 * signal(SIGKILL) marks a pid dead (a successful kill), so a reaper that
 * escalates SIGTERM → SIGKILL → re-probe sees the child die; SIGTERM alone is
 * recorded but leaves the pid alive (a stubborn child), exercising escalation.
 */
final class FakeProbe implements ProcessProbe
{
    /** @var array<int,bool> */
    private array $alive = [];

    /** @var array<int,string> */
    private array $startTimes = [];

    /** @var array<int,int> */
    private array $ppids = [];

    /** @var array<string,array{nonce:string,pid:int}> keyed by attempt id */
    private array $pidfiles = [];

    /** @var array<int,int[]> signals sent per pid */
    private array $signals = [];

    public const SIGTERM = 15;

    public const SIGKILL = 9;

    public function spawn(int $pid, string $startTime, int $ppid = 1, ?string $attemptId = null, ?string $nonce = null): void
    {
        $this->alive[$pid] = true;
        $this->startTimes[$pid] = $startTime;
        $this->ppids[$pid] = $ppid;

        if ($attemptId !== null && $nonce !== null) {
            $this->pidfiles[$attemptId] = ['nonce' => $nonce, 'pid' => $pid];
        }
    }

    public function kill(int $pid): void
    {
        $this->alive[$pid] = false;
    }

    public function setPpid(int $pid, int $ppid): void
    {
        $this->ppids[$pid] = $ppid;
    }

    /** @return int[] */
    public function signalsSentTo(int $pid): array
    {
        return $this->signals[$pid] ?? [];
    }

    public function pidAlive(int $pid): bool
    {
        return $this->alive[$pid] ?? false;
    }

    public function startTime(int $pid): ?string
    {
        return ($this->alive[$pid] ?? false) ? ($this->startTimes[$pid] ?? null) : null;
    }

    public function ppid(int $pid): ?int
    {
        return ($this->alive[$pid] ?? false) ? ($this->ppids[$pid] ?? null) : null;
    }

    public function signal(int $pid, int $signal): bool
    {
        $this->signals[$pid][] = $signal;

        if ($signal === self::SIGKILL) {
            $this->alive[$pid] = false;
        }

        return true;
    }

    public function matches(int $pid, ?string $expectedStartTime): bool
    {
        return $this->pidAlive($pid)
            && $expectedStartTime !== null
            && $this->startTime($pid) === $expectedStartTime;
    }

    public function verify(ProcessStamp $stamp): VerifyResult
    {
        if (! $stamp->hasChild()) {
            return new VerifyResult(false, false, false, 'no child pid (dispatched window)');
        }

        if (! $this->pidAlive($stamp->childPid)) {
            return VerifyResult::dead();
        }

        $startMatch = $stamp->childStartTime !== null
            && $this->startTime($stamp->childPid) === $stamp->childStartTime;

        $pidfile = $this->pidfiles[$stamp->attemptId] ?? null;
        $nonceMatch = $stamp->procNonce !== null
            && $pidfile !== null
            && $pidfile['nonce'] === $stamp->procNonce
            && $pidfile['pid'] === $stamp->childPid;

        return new VerifyResult(true, $startMatch, $nonceMatch);
    }
}
