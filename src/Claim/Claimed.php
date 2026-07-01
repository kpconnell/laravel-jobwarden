<?php

declare(strict_types=1);

namespace JobWarden\Claim;

/**
 * The result of claiming one job: enough for the supervisor to spawn the child
 * (`jobwarden:run {attemptId} --token={fencingToken}`).
 */
final class Claimed
{
    public function __construct(
        public readonly string $jobId,
        public readonly string $attemptId,
        public readonly int $attemptNumber,
        public readonly int $fencingToken,
    ) {
    }
}
