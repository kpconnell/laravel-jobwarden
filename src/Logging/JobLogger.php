<?php

declare(strict_types=1);

namespace JobWarden\Logging;

use JobWarden\Logging\Contracts\LogBodySink;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes LogIndex rows (job_logs) over a pluggable LogBodySink. This is the
 * shared writer used by both the in-child Log-facade bridge (JobLogCapture) AND
 * the reaper-injection seam — a reaper calls write(...) to record what action it
 * took on an attempt, so the job's log explains its own recovery.
 *
 * seq is monotonic per attempt: an attempt has a single live writer (its child),
 * so an in-memory counter (seeded from the DB max) is correct and cheap; a
 * separate process (reaper) seeds fresh from the DB.
 */
final class JobLogger
{
    /** @var array<string,int> attemptId => next seq */
    private array $nextSeq = [];

    public function __construct(private readonly LogBodySink $sink)
    {
    }

    public function write(string $jobId, string $attemptId, string $level, string $message, array $context = [], ?string $step = null): void
    {
        $conn = $this->connection();
        $seq = $this->nextSeq($conn, $attemptId);

        // step is promoted to its own column; don't duplicate it in context.
        unset($context['step']);

        $conn->table($this->tbl('job_logs'))->insert([
            'job_id' => $jobId,
            'attempt_id' => $attemptId,
            'seq' => $seq,
            'ts' => Carbon::now(),
            'level' => $level,
            'step' => $step,
            'context' => $context === [] ? null : json_encode($context, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            'body_sink' => $this->sink->name(),
            'body_ref' => $this->sink->store($attemptId, $seq, $message),
            'created_at' => Carbon::now(),
        ]);
    }

    /** Placeholder for buffered sinks; the database sink writes immediately for live tailing. */
    public function flush(): void
    {
    }

    private function nextSeq(Connection $conn, string $attemptId): int
    {
        if (! isset($this->nextSeq[$attemptId])) {
            $max = (int) $conn->table($this->tbl('job_logs'))->where('attempt_id', $attemptId)->max('seq');
            $this->nextSeq[$attemptId] = $max + 1;
        }

        return $this->nextSeq[$attemptId]++;
    }

    private function connection(): Connection
    {
        return DB::connection(config('jobwarden.connection'));
    }

    private function tbl(string $name): string
    {
        return ((string) config('jobwarden.table_prefix')).$name;
    }
}
