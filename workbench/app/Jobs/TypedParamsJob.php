<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use JobWarden\Contracts\JobWardenJob;
use JobWarden\Runner\JobContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;

/**
 * Exercises constructor param binding: promoted data params (scalar, enum,
 * optional date-time) bound by name from the params JSON, alongside a service
 * resolved by the container. Writes what it received to `marker` so tests can
 * assert the binding end-to-end.
 */
final class TypedParamsJob implements JobWardenJob
{
    public function __construct(
        private readonly Repository $config,            // service: container DI
        private readonly string $marker,                // data: required param
        private readonly ImportMode $mode,              // data: enum, coerced from "full"
        private readonly int $limit = 10,               // data: optional, JSON default
        private readonly ?CarbonImmutable $asOf = null, // data: optional date-time
    ) {
    }

    public function handle(JobContext $context): void
    {
        file_put_contents($this->marker, json_encode([
            'mode' => $this->mode->value,
            'limit' => $this->limit,
            'as_of' => $this->asOf?->toIso8601String(),
            'service_resolved' => $this->config instanceof Repository,
            'context_params' => $context->params, // the full array still flows
        ]));
    }

    public function idempotent(): bool
    {
        return true;
    }
}
