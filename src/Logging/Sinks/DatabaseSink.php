<?php

declare(strict_types=1);

namespace JobWarden\Logging\Sinks;

use JobWarden\Logging\Contracts\LogBodySink;

/** v1 default: the body lives inline in job_logs.body_ref (spec §4.5). */
final class DatabaseSink implements LogBodySink
{
    public function store(string $attemptId, int $seq, string $body): string
    {
        return $body;
    }

    public function resolve(string $bodyRef): ?string
    {
        return $bodyRef;
    }

    public function name(): string
    {
        return 'database';
    }
}
