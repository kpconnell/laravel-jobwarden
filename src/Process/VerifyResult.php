<?php

declare(strict_types=1);

namespace JobWarden\Process;

/**
 * Outcome of verifying a process stamp: the child must be alive AND its
 * start-time AND its nonce must match for the attempt to count as verifiably
 * this attempt's process (spec §5.3).
 */
final class VerifyResult
{
    public function __construct(
        public readonly bool $alive,
        public readonly bool $startTimeMatch,
        public readonly bool $nonceMatch,
        public readonly ?string $detail = null,
    ) {
    }

    public function verified(): bool
    {
        return $this->alive && $this->startTimeMatch && $this->nonceMatch;
    }

    public static function dead(string $detail = 'process not alive'): self
    {
        return new self(false, false, false, $detail);
    }
}
