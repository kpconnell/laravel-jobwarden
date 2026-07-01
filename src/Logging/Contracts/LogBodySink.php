<?php

declare(strict_types=1);

namespace JobWarden\Logging\Contracts;

/**
 * Splits a log record into an index (always in the DB) and a body (pluggable).
 * The database sink stores the body inline; disk/s3 store a pointer (spec §4.5).
 */
interface LogBodySink
{
    /** Persist the body; return what goes in job_logs.body_ref (inline text or a pointer). */
    public function store(string $attemptId, int $seq, string $body): string;

    /** Resolve a body_ref back to its text (for tailing/export). */
    public function resolve(string $bodyRef): ?string;

    /** database|disk|s3|custom — stored in job_logs.body_sink. */
    public function name(): string;
}
