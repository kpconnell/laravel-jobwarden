<?php

declare(strict_types=1);

namespace JobWarden\Tests\Schema;

use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Uid\Uuid;

final class SchemaTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
    }

    public function test_all_mandatory_tables_migrate(): void
    {
        $schema = Schema::connection(config('jobwarden.connection'));

        foreach ([
            'batches', 'schedules', 'workers', 'jobs', 'job_attempts', 'job_events',
            'job_logs', 'job_artifacts', 'schedule_runs', 'job_dependencies', 'leader_leases',
        ] as $t) {
            $this->assertTrue($schema->hasTable($this->tbl($t)), "missing table {$t}");
        }
    }

    public function test_unique_job_id_attempt_number_rejects_duplicate(): void
    {
        $jobId = $this->seedJob();

        $this->insertAttempt($jobId, 1);

        $this->expectException(QueryException::class);
        $this->insertAttempt($jobId, 1); // same (job_id, attempt_number) → collision
    }

    public function test_unique_idempotency_key_rejects_duplicate_but_allows_many_nulls(): void
    {
        $this->seedJob(idempotencyKey: 'charge-order-42');

        // Two NULL keys are fine (multiple NULLs allowed under the SQL standard).
        $this->seedJob(idempotencyKey: null);
        $this->seedJob(idempotencyKey: null);

        $this->assertSame(3, $this->jobwarden()->table($this->tbl('jobs'))->count());

        $this->expectException(QueryException::class);
        $this->seedJob(idempotencyKey: 'charge-order-42'); // duplicate non-null → collision
    }

    public function test_unique_schedule_occurrence_rejects_duplicate(): void
    {
        $scheduleId = (string) Uuid::v7();
        $this->jobwarden()->table($this->tbl('schedules'))->insert([
            'id' => $scheduleId,
            'name' => 'nightly-'.$scheduleId,
            'job_class' => 'App\\Jobs\\Nightly',
            'kind' => 'recurring',
            'missed_policy' => 'run_latest',
            'overlap_policy' => 'skip',
        ]);

        $occurrence = '2026-06-30 02:00:00';
        $this->insertScheduleRun($scheduleId, $occurrence);

        $this->expectException(QueryException::class);
        $this->insertScheduleRun($scheduleId, $occurrence); // same occurrence → collision
    }

    private function seedJob(?string $idempotencyKey = null): string
    {
        $id = (string) Uuid::v7();
        $this->jobwarden()->table($this->tbl('jobs'))->insert([
            'id' => $id,
            'job_class' => 'App\\Jobs\\Demo',
            'state' => 'queued',
            'idempotent' => false,
            'idempotency_key' => $idempotencyKey,
            'priority' => 0,
            'max_attempts' => 1,
            'attempt_count' => 0,
        ]);

        return $id;
    }

    private function insertAttempt(string $jobId, int $number): void
    {
        $this->jobwarden()->table($this->tbl('job_attempts'))->insert([
            'id' => (string) Uuid::v7(),
            'job_id' => $jobId,
            'attempt_number' => $number,
            'state' => 'dispatched',
            'fencing_token' => $number,
        ]);
    }

    private function insertScheduleRun(string $scheduleId, string $occurrence): void
    {
        $this->jobwarden()->table($this->tbl('schedule_runs'))->insert([
            'id' => (string) Uuid::v7(),
            'schedule_id' => $scheduleId,
            'occurrence_time' => $occurrence,
            'action' => 'enqueued',
        ]);
    }
}
