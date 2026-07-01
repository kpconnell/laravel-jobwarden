<?php

declare(strict_types=1);

namespace JobWarden\Process\Contracts;

use JobWarden\Process\ProcessStamp;
use JobWarden\Process\VerifyResult;

/**
 * Abstracts OS process inspection so the rest of the engine stays
 * platform-agnostic. There is one real implementation (Linux, /proc); the
 * interface exists chiefly as a test seam for FakeProbe-driven reaper tests.
 *
 * start-times are OPAQUE comparable strings (Linux: /proc/<pid>/stat field 22).
 */
interface ProcessProbe
{
    public function pidAlive(int $pid): bool;

    public function startTime(int $pid): ?string;

    /** Parent pid — used to detect a child reparented to init (ppid==1). */
    public function ppid(int $pid): ?int;

    public function signal(int $pid, int $signal): bool;

    /** Alive AND its start-time equals the expected one (reuse-proof). */
    public function matches(int $pid, ?string $expectedStartTime): bool;

    /** Full child verification: alive + start-time match + nonce (pidfile) match. */
    public function verify(ProcessStamp $stamp): VerifyResult;
}
