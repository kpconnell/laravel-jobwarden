<?php

declare(strict_types=1);

namespace JobWarden\Runner;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\Uuid;

/**
 * Execution context handed to a JobWardenJob::handle(). Carries identity + params
 * and lets a handler attach support-case artifacts (request/response pairs, files,
 * dumps) that get bundled into `jobwarden:logs --export` (spec §9.3).
 */
final class JobContext
{
    public function __construct(
        public readonly string $jobId,
        public readonly string $attemptId,
        public readonly int $attemptNumber,
        public readonly array $params = [],
    ) {
    }

    /**
     * Record an artifact for this attempt. Pass `path` (+ optional `disk`) for a
     * file on a filesystem disk, or `meta` for an inline summary (e.g. a redacted
     * request/response). Returns the artifact id.
     *
     * @param  array{disk?:?string, path?:?string, size_bytes?:?int, checksum?:?string, content_type?:?string, meta?:?array}  $opts
     */
    public function artifact(string $type, string $name, array $opts = []): string
    {
        $id = (string) Uuid::v7();

        DB::connection(config('jobwarden.connection'))
            ->table(((string) config('jobwarden.table_prefix')).'job_artifacts')
            ->insert([
                'id' => $id,
                'job_id' => $this->jobId,
                'attempt_id' => $this->attemptId,
                'type' => $type,
                'name' => $name,
                'disk' => $opts['disk'] ?? null,
                'path' => $opts['path'] ?? null,
                'size_bytes' => $opts['size_bytes'] ?? null,
                'checksum' => $opts['checksum'] ?? null,
                'content_type' => $opts['content_type'] ?? null,
                'meta' => isset($opts['meta']) ? json_encode($opts['meta']) : null,
                'created_at' => Carbon::now(),
            ]);

        return $id;
    }
}
