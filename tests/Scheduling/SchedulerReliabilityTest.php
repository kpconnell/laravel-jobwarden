<?php

declare(strict_types=1);

namespace JobWarden\Tests\Scheduling;

use JobWarden\Models\Job;
use JobWarden\Models\Schedule;
use JobWarden\Models\ScheduleRun;
use JobWarden\Scheduling\ScheduleEvaluator;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Illuminate\Support\Carbon;
use Symfony\Component\Uid\Uuid;

final class SchedulerReliabilityTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-30 12:00:00', 'UTC'));
        $this->setUpJobWardenSchema();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_large_missed_run_backlog_is_fully_audited_and_re_evaluation_does_not_duplicate_jobs(): void
    {
        $schedule = $this->schedule(lastEvaluatedMinutesAgo: 200, missedPolicy: 'run_all', overrides: [
            'max_catch_up' => 50,
            'idempotent' => true,
        ]);

        $first = $this->evaluator()->evaluate($schedule->id, (string) Uuid::v7());

        $this->assertSame(50, $first);
        $this->assertSame(50, Job::where('schedule_id', $schedule->id)->count());
        $this->assertSame(200, ScheduleRun::where('schedule_id', $schedule->id)->count());
        $this->assertSame(50, ScheduleRun::where('schedule_id', $schedule->id)->where('action', 'enqueued')->count());
        $this->assertSame(150, ScheduleRun::where('schedule_id', $schedule->id)->where('action', 'skipped')->count());

        $this->assertSame(
            200,
            ScheduleRun::where('schedule_id', $schedule->id)->distinct('occurrence_time')->count('occurrence_time'),
            'every occurrence gets exactly one durable audit row'
        );

        $this->assertSame(
            0,
            ScheduleRun::where('schedule_id', $schedule->id)
                ->where('action', 'enqueued')
                ->whereNull('job_id')
                ->count(),
            'every enqueued occurrence is linked to its materialized job'
        );

        // Force a second evaluator to see the same old window. The unique
        // occurrence key must turn the whole pass into a no-op.
        Schedule::where('id', $schedule->id)->update([
            'last_evaluated_at' => Carbon::now()->subMinutes(200),
        ]);

        $second = $this->evaluator()->evaluate($schedule->id, (string) Uuid::v7());

        $this->assertSame(0, $second);
        $this->assertSame(50, Job::where('schedule_id', $schedule->id)->count());
        $this->assertSame(200, ScheduleRun::where('schedule_id', $schedule->id)->count());
    }

    public function test_once_an_occurrence_is_audited_a_later_policy_change_cannot_rewrite_it_into_a_job(): void
    {
        $schedule = $this->schedule(lastEvaluatedMinutesAgo: 20, missedPolicy: 'skip');

        $this->assertSame(0, $this->evaluator()->evaluate($schedule->id, (string) Uuid::v7()));
        $this->assertSame(20, ScheduleRun::where('schedule_id', $schedule->id)->where('action', 'skipped')->count());

        Schedule::where('id', $schedule->id)->update([
            'missed_policy' => 'run_all',
            'last_evaluated_at' => Carbon::now()->subMinutes(20),
        ]);

        $this->assertSame(0, $this->evaluator()->evaluate($schedule->id, (string) Uuid::v7()));
        $this->assertSame(0, Job::where('schedule_id', $schedule->id)->count());
        $this->assertSame(20, ScheduleRun::where('schedule_id', $schedule->id)->where('action', 'skipped')->count());
        $this->assertSame(0, ScheduleRun::where('schedule_id', $schedule->id)->where('action', 'enqueued')->count());
    }

    public function test_scheduler_materializes_retry_contract_for_many_idempotent_runs(): void
    {
        $schedule = $this->schedule(lastEvaluatedMinutesAgo: 30, missedPolicy: 'run_all', overrides: [
            'idempotent' => true,
            'priority' => 7,
        ]);

        $this->assertSame(30, $this->evaluator()->evaluate($schedule->id));

        $this->assertSame(0, Job::where('schedule_id', $schedule->id)->where('lane', '!=', 'scheduled')->count());
        $this->assertSame(0, Job::where('schedule_id', $schedule->id)->where('idempotent', false)->count());
        $this->assertSame(0, Job::where('schedule_id', $schedule->id)->where('max_attempts', '!=', 3)->count());
        $this->assertSame(0, Job::where('schedule_id', $schedule->id)->where('priority', '!=', 7)->count());
        $this->assertSame(30, Job::where('schedule_id', $schedule->id)->whereNotNull('queued_at')->count());
    }

    private function evaluator(): ScheduleEvaluator
    {
        return $this->app->make(ScheduleEvaluator::class);
    }

    /** @param array<string,mixed> $overrides */
    private function schedule(int $lastEvaluatedMinutesAgo, string $missedPolicy, array $overrides = []): Schedule
    {
        return Schedule::create(array_merge([
            'name' => 'reliability-'.bin2hex(random_bytes(4)),
            'job_class' => 'App\\Jobs\\Tick',
            'kind' => 'recurring',
            'cron_expression' => '* * * * *',
            'timezone' => 'UTC',
            'enabled' => true,
            'missed_policy' => $missedPolicy,
            'overlap_policy' => 'allow',
            'last_evaluated_at' => Carbon::now()->subMinutes($lastEvaluatedMinutesAgo),
        ], $overrides));
    }
}
