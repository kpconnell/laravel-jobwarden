<?php

declare(strict_types=1);

namespace JobWarden\Process;

use JobWarden\Models\JobAttempt;

/**
 * The verifiable identity of the OS processes that own an attempt's execution
 * (spec §5.3). start-times are kept as OPAQUE comparable strings — the system
 * only ever tests them for equality, never arithmetic.
 */
final class ProcessStamp
{
    public function __construct(
        public readonly string $attemptId,
        public readonly ?string $hostId = null,
        public readonly ?int $supervisorPid = null,
        public readonly ?string $supervisorStartTime = null,
        public readonly ?int $childPid = null,
        public readonly ?string $childStartTime = null,
        public readonly ?string $procNonce = null,
    ) {
    }

    public static function fromAttempt(JobAttempt $attempt): self
    {
        return new self(
            attemptId: (string) $attempt->id,
            hostId: $attempt->host_id,
            supervisorPid: $attempt->supervisor_pid,
            supervisorStartTime: self::str($attempt->supervisor_start_time),
            childPid: $attempt->child_pid,
            childStartTime: self::str($attempt->child_start_time),
            procNonce: $attempt->proc_nonce,
        );
    }

    public function hasChild(): bool
    {
        return $this->childPid !== null;
    }

    private static function str(mixed $v): ?string
    {
        return $v === null ? null : (string) $v;
    }
}
