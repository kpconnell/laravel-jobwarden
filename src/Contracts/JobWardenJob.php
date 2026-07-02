<?php

declare(strict_types=1);

namespace JobWarden\Contracts;

use JobWarden\Runner\JobContext;

/**
 * The opt-in contract a JobWarden job handler implements. Resolved from the
 * container by `jobs.job_class` and executed in the dedicated child process —
 * the job's stored params (JSON) bind to constructor parameters BY NAME, with
 * services container-resolved as usual (see Runner\HandlerFactory and
 * docs/JOB-AUTHORING.md).
 *
 * JobWarden coexists with Laravel's Bus/Queue — it does not hijack dispatch().
 */
interface JobWardenJob
{
    /**
     * Execute the job. Returning normally = success (exit 0). Throwing = failure.
     */
    public function handle(JobContext $context): void;

    /**
     * THE guard (spec §3.4): the single attribute that decides whether a lost
     * or failed run may be automatically restarted. `false` keeps a dead/lost
     * attempt indeterminate and parks it for an operator.
     */
    public function idempotent(): bool;
}
