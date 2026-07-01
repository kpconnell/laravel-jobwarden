<?php

declare(strict_types=1);

namespace JobWarden\Claim;

use JobWarden\Claim\Contracts\ClaimDriver;
use JobWarden\StateMachine\StateMachine;
use Illuminate\Support\Facades\DB;

/**
 * Selects the claim driver from config (`jobwarden.claim.driver` =
 * auto|skip_locked|optimistic). `auto` uses SKIP LOCKED on capable engines
 * (incl. SQLite, single writer — the lock clause is dropped), and falls back to
 * the optimistic guarded-UPDATE driver on concurrent engines without SKIP LOCKED.
 */
final class ClaimDriverFactory
{
    public function __construct(private readonly StateMachine $stateMachine)
    {
    }

    public function make(): ClaimDriver
    {
        $conn = DB::connection(config('jobwarden.connection'));
        $inspector = new EngineInspector($conn);
        $configured = (string) config('jobwarden.claim.driver', 'auto');

        return match ($configured) {
            'skip_locked' => new SkipLockedClaimDriver($this->stateMachine),
            'optimistic' => new OptimisticClaimDriver($this->stateMachine),
            default => $this->auto($inspector),
        };
    }

    private function auto(EngineInspector $inspector): ClaimDriver
    {
        if ($inspector->supportsSkipLocked() || $inspector->driver() === 'sqlite') {
            return new SkipLockedClaimDriver($this->stateMachine);
        }

        // A real concurrent engine without SKIP LOCKED: the optimistic guarded-
        // UPDATE driver gives the same no-double-claim guarantee, lock-free.
        return new OptimisticClaimDriver($this->stateMachine);
    }
}
