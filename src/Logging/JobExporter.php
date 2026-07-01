<?php

declare(strict_types=1);

namespace JobWarden\Logging;

use JobWarden\Logging\Contracts\LogBodySink;
use JobWarden\Models\Job;
use Generator;

/**
 * Streams a self-contained NDJSON support bundle for a job (spec §9.3): one JSON
 * record per line, typed, covering the job, its attempts, its full event audit,
 * its logs (bodies resolved through the sink), and its request/response artifacts.
 * Streamed as a generator so a huge job doesn't have to fit in memory.
 */
final class JobExporter
{
    public function __construct(private readonly LogBodySink $sink)
    {
    }

    /** @return Generator<string> NDJSON lines */
    public function export(Job $job): Generator
    {
        yield $this->record('job', $job->toArray());

        foreach ($job->attempts()->orderBy('attempt_number')->cursor() as $attempt) {
            yield $this->record('attempt', $attempt->toArray());
        }

        foreach ($job->events()->orderBy('id')->cursor() as $event) {
            yield $this->record('event', $event->toArray());
        }

        foreach ($job->logs()->orderBy('ts')->orderBy('id')->cursor() as $log) {
            $data = $log->toArray();
            $data['body'] = $this->sink->resolve((string) $log->body_ref);
            yield $this->record('log', $data);
        }

        foreach ($job->artifacts()->cursor() as $artifact) {
            yield $this->record('artifact', $artifact->toArray());
        }
    }

    private function record(string $type, array $data): string
    {
        return json_encode(
            ['type' => $type, 'data' => $data],
            JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
        ).PHP_EOL;
    }
}
