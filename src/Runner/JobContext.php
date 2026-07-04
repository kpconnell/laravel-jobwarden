<?php

declare(strict_types=1);

namespace JobWarden\Runner;

use Closure;
use JobWarden\Support\SqlTime;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\Uuid;

/**
 * The per-attempt capability handle passed to JobWardenJob::handle(). Carries
 * execution identity, lets a handler attach support-case artifacts
 * (request/response pairs, files, dumps) that get bundled into
 * `jobwarden:logs --export` (spec §9.3), buffers the completion payload set via
 * result(), and exposes the cooperative stop flag. The job's DATA does not live
 * here — params bind to the handler's constructor (see Runner\HandlerFactory);
 * services are injected into handle() by the container.
 */
final class JobContext
{
    /** Buffered completion payload — committed by ChildRunner with the succeeded transition. */
    private ?array $result = null;

    public function __construct(
        public readonly string $jobId,
        public readonly string $attemptId,
        public readonly int $attemptNumber,
        private readonly ?Closure $stopSignal = null,
    ) {
    }

    /**
     * Has the supervisor asked this run to stop (SIGTERM: deploy, scale-down)?
     * Cooperation is optional — a long-running handler that polls this in its
     * loop can checkpoint and return within the grace window; one that doesn't
     * is SIGKILLed after the grace period and recorded `stopped` by the
     * supervisor. Correctness never depends on checking it.
     */
    public function stopRequested(): bool
    {
        return $this->stopSignal !== null && ($this->stopSignal)();
    }

    /**
     * Store the job's completion payload (`jobs.result`). Buffered here and
     * committed in the SAME transaction as the succeeded transition, so a poller
     * can never observe `state = succeeded` without its result — and a fenced-out
     * child (reaped while finishing) never lands one. Last call wins; nothing is
     * persisted unless the run succeeds.
     *
     * Encodability and size are validated HERE so a bad payload fails the run at
     * the call site (a normal, loud handler failure) instead of inside the success
     * commit. Payloads over `jobwarden.results.max_bytes` belong in an artifact —
     * store the file and put the artifact id in the result instead.
     */
    public function result(array $payload): void
    {
        $bytes = strlen(json_encode($payload, JSON_THROW_ON_ERROR));

        $max = (int) config('jobwarden.results.max_bytes', 65536);
        if ($bytes > $max) {
            throw new \InvalidArgumentException(
                "Job result is {$bytes} bytes, over the jobwarden.results.max_bytes cap ({$max}); ".
                'store large payloads as an artifact and put the artifact id in the result.'
            );
        }

        $this->result = $payload;
    }

    /** @internal Read by ChildRunner when committing success. */
    public function bufferedResult(): ?array
    {
        return $this->result;
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

        $conn = DB::connection(config('jobwarden.connection'));
        $conn->table(((string) config('jobwarden.table_prefix')).'job_artifacts')
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
                'created_at' => $conn->raw(SqlTime::nowExpr($conn)),
            ]);

        return $id;
    }
}
