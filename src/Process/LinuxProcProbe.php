<?php

declare(strict_types=1);

namespace JobWarden\Process;

use JobWarden\Process\Contracts\ProcessProbe;

/**
 * The one real probe (production target). Liveness and reuse-proof identity come
 * from /proc:
 *  - existence: is_dir("/proc/<pid>") — unambiguous (avoids the posix_kill
 *    EPERM-means-alive trap on shared hosts);
 *  - start-time: /proc/<pid>/stat field 22 (starttime ticks since boot), kept as
 *    an opaque string (no CLK_TCK math);
 *  - ppid: field 4 (to detect a child reparented to init).
 */
final class LinuxProcProbe implements ProcessProbe
{
    public function __construct(private readonly Pidfile $pidfile)
    {
    }

    public function pidAlive(int $pid): bool
    {
        return $pid > 0 && is_dir("/proc/{$pid}");
    }

    public function startTime(int $pid): ?string
    {
        $stat = @file_get_contents("/proc/{$pid}/stat");

        return $stat === false ? null : self::parseField($stat, 22);
    }

    public function ppid(int $pid): ?int
    {
        $stat = @file_get_contents("/proc/{$pid}/stat");
        if ($stat === false) {
            return null;
        }

        $field = self::parseField($stat, 4);

        return $field === null ? null : (int) $field;
    }

    public function signal(int $pid, int $signal): bool
    {
        return $pid > 0 && @posix_kill($pid, $signal);
    }

    public function matches(int $pid, ?string $expectedStartTime): bool
    {
        if (! $this->pidAlive($pid)) {
            return false;
        }

        $actual = $this->startTime($pid);

        return $actual !== null && $expectedStartTime !== null && $actual === $expectedStartTime;
    }

    public function verify(ProcessStamp $stamp): VerifyResult
    {
        if (! $stamp->hasChild()) {
            return new VerifyResult(false, false, false, 'no child pid (dispatched window)');
        }

        if (! $this->pidAlive($stamp->childPid)) {
            return VerifyResult::dead();
        }

        $startTimeMatch = $this->startTime($stamp->childPid) === $stamp->childStartTime
            && $stamp->childStartTime !== null;

        return new VerifyResult(true, $startTimeMatch, $this->nonceMatches($stamp));
    }

    private function nonceMatches(ProcessStamp $stamp): bool
    {
        if ($stamp->procNonce === null) {
            return false;
        }

        $pidfile = $this->pidfile->read($stamp->attemptId);

        return $pidfile !== null
            && ($pidfile['nonce'] ?? null) === $stamp->procNonce
            && (int) ($pidfile['pid'] ?? 0) === $stamp->childPid;
    }

    /**
     * Parse a 1-indexed field from a /proc/<pid>/stat line. comm (field 2) is
     * parenthesized and may itself contain spaces and parens, so we split AFTER
     * the last ')'. Fields 3+ live in the remainder.
     */
    public static function parseField(string $stat, int $field): ?string
    {
        $rparen = strrpos($stat, ')');
        if ($rparen === false || $field < 3) {
            // Fields 1 (pid) and 2 (comm) aren't supported by this parser.
            return $field === 1 ? (string) (int) $stat : null;
        }

        $rest = trim(substr($stat, $rparen + 1));
        $parts = preg_split('/\s+/', $rest) ?: [];

        // $parts[0] is field 3 (state); field N → index N - 3.
        return $parts[$field - 3] ?? null;
    }
}
