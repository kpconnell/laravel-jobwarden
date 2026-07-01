<?php

declare(strict_types=1);

namespace JobWarden\StateMachine;

use JobWarden\States\ActorType;

/**
 * Everything the StateMachine needs to validate and record a transition: who is
 * acting, why, the fencing expectations, and the context/process snapshot to
 * persist in the job_events audit row.
 */
final class TransitionContext
{
    public function __construct(
        public ActorType $actorType,
        public ?string $actorId = null,
        public ?string $reason = null,
        /**
         * Concurrency-sensitive guard: when set, the guarded UPDATE additionally
         * requires `fencing_token = :expectedFencingToken`. A stale (zombie /
         * partitioned) writer carrying an old epoch is rejected (affected==0).
         */
        public ?int $expectedFencingToken = null,
        /** Orphan transitions bump the epoch so the old owner is fenced out. */
        public bool $fencingBump = false,
        /** @var array<string,mixed> persisted into job_events.context */
        public array $context = [],
        /** @var array<string,mixed> process stamp snapshot, merged into context */
        public array $processSnapshot = [],
    ) {
    }

    public static function for(ActorType $actorType, ?string $actorId = null, ?string $reason = null): self
    {
        return new self($actorType, $actorId, $reason);
    }

    public function expectingToken(int $token): self
    {
        $this->expectedFencingToken = $token;

        return $this;
    }

    public function bumpingFence(bool $bump = true): self
    {
        $this->fencingBump = $bump;

        return $this;
    }

    public function because(string $reason): self
    {
        $this->reason = $reason;

        return $this;
    }

    /** @param array<string,mixed> $context */
    public function withContext(array $context): self
    {
        $this->context = array_merge($this->context, $context);

        return $this;
    }

    /** @param array<string,mixed> $snapshot */
    public function withProcessSnapshot(array $snapshot): self
    {
        $this->processSnapshot = $snapshot;

        return $this;
    }

    /** @return array<string,mixed> */
    public function eventContext(?int $fencingToken): array
    {
        $context = $this->context;

        if ($this->processSnapshot !== []) {
            $context['process'] = $this->processSnapshot;
        }

        if ($fencingToken !== null) {
            $context['fencing_token'] = $fencingToken;
        }

        return $context;
    }
}
