<?php

declare(strict_types=1);

namespace JobWarden\Tests\Logging;

use JobWarden\Logging\JobExporter;
use JobWarden\Models\Job;
use JobWarden\Models\JobAttempt;
use JobWarden\Models\JobEvent;
use JobWarden\Models\JobLog;
use JobWarden\Runner\JobContext;
use JobWarden\States\AttemptState;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;

final class JobExporterTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
    }

    public function test_export_emits_a_typed_ndjson_bundle_of_everything_about_a_job(): void
    {
        $job = Job::create([
            'job_class' => 'App\\Jobs\\Charge',
            'state' => JobState::Succeeded,
            'params' => ['amount' => 4200, 'currency' => 'usd'],
            'finished_at' => now(),
        ]);
        $attempt = JobAttempt::create(['job_id' => $job->id, 'attempt_number' => 1, 'state' => AttemptState::Succeeded, 'fencing_token' => 1]);
        JobEvent::create(['job_id' => $job->id, 'attempt_id' => $attempt->id, 'level' => 'attempt', 'to_state' => 'succeeded', 'actor_type' => 'worker', 'created_at' => now()]);
        JobLog::create(['job_id' => $job->id, 'attempt_id' => $attempt->id, 'seq' => 1, 'ts' => now(), 'level' => 'info', 'body_sink' => 'database', 'body_ref' => 'charged card ok']);

        // A handler-recorded artifact (inline summary, no file).
        (new JobContext($job->id, $attempt->id, 1, []))->artifact('response', 'stripe-response', [
            'content_type' => 'application/json',
            'meta' => ['status' => 200, 'id' => 'ch_123'],
        ]);

        $records = $this->parse($this->app->make(JobExporter::class)->export($job));

        // One record per type, correctly tagged.
        $byType = [];
        foreach ($records as $r) {
            $byType[$r['type']][] = $r['data'];
        }
        $this->assertCount(1, $byType['job']);
        $this->assertCount(1, $byType['attempt']);
        $this->assertCount(1, $byType['event']);
        $this->assertCount(1, $byType['log']);
        $this->assertCount(1, $byType['artifact']);

        // The job record carries its params; the log record carries the resolved body.
        $this->assertSame(['amount' => 4200, 'currency' => 'usd'], $byType['job'][0]['params']);
        $this->assertSame('charged card ok', $byType['log'][0]['body']);
        $this->assertSame('stripe-response', $byType['artifact'][0]['name']);
        $this->assertSame(['status' => 200, 'id' => 'ch_123'], $byType['artifact'][0]['meta']);
    }

    public function test_each_line_is_independently_valid_json(): void
    {
        $job = Job::create(['job_class' => 'X', 'state' => JobState::Queued]);

        $lines = iterator_to_array($this->app->make(JobExporter::class)->export($job));

        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertStringEndsWith("\n", $line);
            $decoded = json_decode(trim($line), true);
            $this->assertIsArray($decoded, "each NDJSON line must parse: {$line}");
            $this->assertArrayHasKey('type', $decoded);
            $this->assertArrayHasKey('data', $decoded);
        }
    }

    /** @param iterable<string> $lines */
    private function parse(iterable $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $out[] = json_decode(trim($line), true);
        }

        return $out;
    }
}
