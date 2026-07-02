<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use JobWarden\Contracts\JobWardenJob;
use JobWarden\Runner\JobContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;

/**
 * Exercises the two halves of handler DI end-to-end: a data-only constructor
 * (scalar, enum, optional date-time) bound by name from the params JSON, and a
 * service method-injected into handle() by the container. Writes what it
 * received to `marker` so tests can assert both bindings.
 */
final class TypedParamsJob implements JobWardenJob
{
    public function __construct(
        private readonly string $marker,                // data: required param
        private readonly ImportMode $mode,              // data: enum, coerced from "full"
        private readonly int $limit = 10,               // data: optional, JSON default
        private readonly ?CarbonImmutable $asOf = null, // data: optional date-time
    ) {
    }

    public function handle(JobContext $context, ?Repository $config = null): void
    {
        file_put_contents($this->marker, json_encode([
            'mode' => $this->mode->value,
            'limit' => $this->limit,
            'as_of' => $this->asOf?->toIso8601String(),
            'service_resolved' => $config instanceof Repository, // handle() method injection
            'attempt' => $context->attemptNumber,
        ]));
    }

    public function idempotent(): bool
    {
        return true;
    }
}
