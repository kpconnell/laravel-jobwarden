<?php

declare(strict_types=1);

namespace JobWarden\Tests\Scheduling;

use JobWarden\JobWarden;
use JobWarden\Models\Job;
use JobWarden\Models\Schedule;
use JobWarden\Models\ScheduleRun;
use JobWarden\Scheduling\ScheduleEvaluator;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Illuminate\Support\Carbon;

final class SchedulerTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        // Deterministic wall clock: 12:05:00. The evaluator reads Carbon::now().
        Carbon::setTestNow(Carbon::parse('2026-06-30 12:05:00', 'UTC'));
        $this->setUpJobWardenSchema();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function evaluator(): ScheduleEvaluator
    {
        return $this->app->make(ScheduleEvaluator::class);
    }

    private function everyTenMin(string $missedPolicy, ?int $lastEvalMinutesAgo = 65): Schedule
    {
        return Schedule::create([
            'name' => 'job-'.bin2hex(random_bytes(3)),
            'job_class' => 'App\\Jobs\\Tick',
            'kind' => 'recurring',
            'cron_expression' => '*/10 * * * *',
            'timezone' => 'UTC',
            'enabled' => true,
            'missed_policy' => $missedPolicy,
            'overlap_policy' => 'allow',
            'last_evaluated_at' => $lastEvalMinutesAgo !== null ? Carbon::now()->subMinutes($lastEvalMinutesAgo) : null,
        ]);
    }

    public function test_missed_run_detection_run_all_enqueues_every_missed_occurrence(): void
    {
        $schedule = $this->everyTenMin('run_all'); // down for 65 min

        $enqueued = $this->evaluator()->evaluate($schedule->id);

        // (11:00, 12:05] at */10 → 11:10,11:20,11:30,11:40,11:50,12:00 = 6
        $this->assertSame(6, $enqueued);
        $this->assertSame(6, Job::where('schedule_id', $schedule->id)->count());
        $this->assertSame(6, ScheduleRun::where('schedule_id', $schedule->id)->where('action', 'enqueued')->count());
        $this->assertNotNull(Schedule::find($schedule->id)->last_evaluated_at);
    }

    public function test_run_latest_enqueues_only_the_most_recent(): void
    {
        $schedule = $this->everyTenMin('run_latest');

        $enqueued = $this->evaluator()->evaluate($schedule->id);

        $this->assertSame(1, $enqueued);
        $this->assertSame(1, Job::where('schedule_id', $schedule->id)->count());
        // the rest are recorded as skipped, for audit
        $this->assertSame(5, ScheduleRun::where('schedule_id', $schedule->id)->where('action', 'skipped')->count());
    }

    public function test_coalesce_enqueues_one_and_records_the_rest_coalesced(): void
    {
        $schedule = $this->everyTenMin('coalesce');

        $this->assertSame(1, $this->evaluator()->evaluate($schedule->id));
        $this->assertSame(5, ScheduleRun::where('schedule_id', $schedule->id)->where('action', 'coalesced')->count());
    }

    public function test_skip_policy_enqueues_nothing_but_advances(): void
    {
        $schedule = $this->everyTenMin('skip');

        $this->assertSame(0, $this->evaluator()->evaluate($schedule->id));
        $this->assertSame(0, Job::where('schedule_id', $schedule->id)->count());
        $this->assertEquals(Carbon::now()->timestamp, Schedule::find($schedule->id)->last_evaluated_at->timestamp);
    }

    public function test_catch_up_window_drops_occurrences_that_are_too_old(): void
    {
        $schedule = $this->everyTenMin('run_all');
        $schedule->update(['catch_up_window_sec' => 25 * 60]); // only the last 25 min

        $enqueued = $this->evaluator()->evaluate($schedule->id);

        // window floor = 12:05 − 25m = 11:40 (inclusive) → 11:40, 11:50, 12:00 = 3
        $this->assertSame(3, $enqueued);
        $this->assertSame(3, ScheduleRun::where('schedule_id', $schedule->id)->where('action', 'outside_window')->count());
    }

    public function test_max_catch_up_caps_the_number_of_jobs(): void
    {
        $schedule = $this->everyTenMin('run_all');
        $schedule->update(['max_catch_up' => 2]);

        $this->assertSame(2, $this->evaluator()->evaluate($schedule->id));
    }

    public function test_overlap_skip_does_not_enqueue_while_a_prior_run_is_active(): void
    {
        $schedule = $this->everyTenMin('run_all', lastEvalMinutesAgo: 15);
        $schedule->update(['overlap_policy' => 'skip']);
        Job::create(['job_class' => 'X', 'schedule_id' => $schedule->id, 'state' => JobState::Running, 'started_at' => now()]);

        $this->assertSame(0, $this->evaluator()->evaluate($schedule->id));
    }

    public function test_re_evaluation_is_idempotent_no_duplicate_jobs(): void
    {
        $schedule = $this->everyTenMin('run_all');

        $first = $this->evaluator()->evaluate($schedule->id);
        // A second evaluator races on the SAME window (force by resetting last_evaluated_at).
        $schedule->update(['last_evaluated_at' => Carbon::now()->subMinutes(65)]);
        $second = $this->evaluator()->evaluate($schedule->id);

        $this->assertSame(6, $first);
        $this->assertSame(0, $second, 'occurrences already materialized are not re-enqueued');
        $this->assertSame(6, Job::where('schedule_id', $schedule->id)->count());
    }

    public function test_one_time_schedule_fires_once_and_never_again(): void
    {
        $schedule = $this->app->make(JobWarden::class)->scheduleOnce(
            'one-shot',
            Carbon::now()->subMinute(), // due
            'App\\Jobs\\Once'
        );

        $this->assertSame(1, $this->evaluator()->evaluate($schedule->id));
        // Re-evaluate: already materialized → never re-fires.
        $this->assertSame(0, $this->evaluator()->evaluate($schedule->id));
        $this->assertSame(1, Job::where('schedule_id', $schedule->id)->count());
    }

    public function test_a_broken_schedule_does_not_crash_the_tick(): void
    {
        // A bad cron slipped into the table (e.g. from a direct write). The tick
        // must isolate it — log + skip — and still fire the healthy schedules,
        // because OS restart can't fix a deterministic per-schedule crash.
        $broken = Schedule::create([
            'name' => 'broken', 'job_class' => 'X', 'kind' => 'recurring',
            'cron_expression' => 'not-a-cron', 'timezone' => 'UTC', 'enabled' => true,
            'missed_policy' => 'run_all', 'overlap_policy' => 'allow',
            'last_evaluated_at' => Carbon::now()->subMinutes(65),
        ]);
        $healthy = $this->everyTenMin('run_all');

        $this->artisan('jobwarden:schedule', ['--once' => true])->assertExitCode(0);

        $this->assertSame(0, Job::where('schedule_id', $broken->id)->count(), 'the broken schedule fired nothing');
        $this->assertGreaterThan(0, Job::where('schedule_id', $healthy->id)->count(), 'the healthy schedule still fired');
    }

    public function test_scheduling_with_an_invalid_cron_is_rejected_at_creation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(JobWarden::class)->schedule('bad', '99 99 * * *', 'App\\Jobs\\X');
    }

    public function test_schedule_command_with_an_invalid_cron_is_rejected_at_creation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(JobWarden::class)->scheduleCommand('bad', 'garbage', 'cache:prune');
    }
}
