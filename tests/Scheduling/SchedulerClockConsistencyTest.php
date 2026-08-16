<?php

declare(strict_types=1);

namespace JobWarden\Tests\Scheduling;

use JobWarden\JobWarden;
use JobWarden\Models\Job;
use JobWarden\Models\Schedule;
use JobWarden\Models\ScheduleRun;
use JobWarden\Scheduling\ScheduleEvaluator;
use JobWarden\Support\SqlTime;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;

/**
 * The scheduler half of DbClockConsistencyTest: every instant the evaluator writes must
 * land on the DB clock, and every instant it reads back for window math must be the true
 * one. The evaluator used to do both with Carbon::now() in app.timezone, which produced
 * three distinct failures under an app↔DB timezone offset:
 *
 *   - schedule_runs.created_at / detected_at and schedules.last_evaluated_at stored
 *     local wall-clock (the reported anti-pattern) — the dashboard read them hours off;
 *   - jobs.available_at landed ahead of the DB clock, and the claim gates on
 *     `available_at <= CURRENT_TIMESTAMP`, so scheduled jobs sat unclaimable;
 *   - on Postgres `timestamptz` a bare literal does not round-trip, so last_evaluated_at
 *     re-read in the app timezone landed in the FUTURE — every window came back empty
 *     and the schedule silently stopped firing forever.
 *
 * Asia/Tokyo (ahead of UTC) is the harsher direction and triggers all three. Crucially
 * these tests must NOT freeze the clock: under Carbon::setTestNow() the DB clock and the
 * app clock collapse into the same literal (SqlTime substitutes the frozen frame), which
 * is exactly why every other test in this directory was blind to the bug.
 */
final class SchedulerClockConsistencyTest extends TestCase
{
    use RefreshesJobWardenSchema;

    /** SqlTime::now() reads whole seconds; the DB round trip adds a little more. */
    private const TOLERANCE_SECONDS = 5;

    private ?string $originalDefaultTz = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefaultTz = date_default_timezone_get();
        // Ahead of the DB by default — the direction that makes a bad WRITE unclaimable.
        // The read-side test flips to a zone behind it; both directions have to hold.
        $this->useTimezone('Asia/Tokyo');

        $this->setUpJobWardenSchema();
    }

    private function useTimezone(string $tz): void
    {
        config(['app.timezone' => $tz]);
        date_default_timezone_set($tz);
    }

    protected function tearDown(): void
    {
        if ($this->originalDefaultTz !== null) {
            date_default_timezone_set($this->originalDefaultTz);
        }

        parent::tearDown();
    }

    public function test_every_timestamp_the_evaluator_writes_lands_on_the_db_clock(): void
    {
        $schedule = $this->everyTenMinutes('run_latest');
        $conn = $schedule->getConnection();
        $this->seedLastEvaluated($schedule, 3900); // down for 65 min

        $this->assertSame(1, $this->evaluator()->evaluate($schedule->id), 'run_latest should enqueue exactly one');

        $run = ScheduleRun::where('schedule_id', $schedule->id)->where('action', 'enqueued')->firstOrFail();
        $job = Job::where('schedule_id', $schedule->id)->firstOrFail();

        $this->assertOnDbClock($conn, $schedule->getTable(), 'last_evaluated_at', $schedule->id);
        $this->assertOnDbClock($conn, $run->getTable(), 'created_at', $run->id);
        $this->assertOnDbClock($conn, $run->getTable(), 'detected_at', $run->id);
        $this->assertOnDbClock($conn, $job->getTable(), 'available_at', $job->id);
        $this->assertOnDbClock($conn, $job->getTable(), 'queued_at', $job->id);
        $this->assertOnDbClock($conn, $job->getTable(), 'created_at', $job->id);
    }

    public function test_the_scheduled_job_is_claimable_against_the_db_clock(): void
    {
        $schedule = $this->everyTenMinutes('run_latest');
        $this->seedLastEvaluated($schedule, 3900);

        $this->evaluator()->evaluate($schedule->id);
        $job = Job::where('schedule_id', $schedule->id)->firstOrFail();

        // An app.timezone ahead of the DB used to write available_at 9h into the future,
        // so the run sat in `queued` untouched until the offset elapsed.
        $conn = $job->getConnection();
        $claimable = (int) $conn->selectOne(
            'SELECT CASE WHEN available_at IS NULL OR available_at <= CURRENT_TIMESTAMP THEN 1 ELSE 0 END AS ok'
            .' FROM '.$job->getTable().' WHERE id = ?',
            [$job->id]
        )->ok;

        $this->assertSame(1, $claimable, 'the scheduled job is not claimable — available_at is ahead of the DB clock');
    }

    public function test_a_stale_last_evaluated_at_is_read_back_in_the_db_frame(): void
    {
        // The read half, and the trap in fixing only the write half: last_evaluated_at is
        // written on the DB clock, so it must be read back off it too. MariaDB returns a
        // bare string that Eloquent's cast parses in app.timezone, which under a zone
        // BEHIND the DB lands the value in the future — an empty window on every tick.
        $this->useTimezone('America/New_York');

        $schedule = $this->everyTenMinutes('run_all');
        $this->seedLastEvaluated($schedule, 3900); // down for 65 min

        $enqueued = $this->evaluator()->evaluate($schedule->id);

        // A 65-minute outage at */10 is 6 or 7 occurrences — no more, and never zero. Both
        // bounds matter: a skew forward empties the window, a skew backward widens it to
        // the whole offset (an hours-long false backfill).
        $this->assertGreaterThanOrEqual(6, $enqueued, 'the missed-run window came back empty — last_evaluated_at read ahead of the DB clock');
        $this->assertLessThanOrEqual(7, $enqueued, 'the window was wider than the outage — last_evaluated_at read behind the DB clock');
    }

    public function test_a_one_time_schedules_run_at_survives_the_round_trip(): void
    {
        $warden = $this->app->make(JobWarden::class);

        $due = $warden->scheduleOnce('once-due-'.bin2hex(random_bytes(3)), Carbon::now()->subMinutes(5), 'App\\Jobs\\Tick');
        $notDue = $warden->scheduleOnce('once-later-'.bin2hex(random_bytes(3)), Carbon::now()->addHours(2), 'App\\Jobs\\Tick');

        // run_at gates the fire (`run_at <= now`), so an offset in either direction makes a
        // one-time schedule fire at the wrong instant — or never.
        $this->assertSame(1, $this->evaluator()->evaluate($due->id), 'a past run_at must fire');
        $this->assertSame(0, $this->evaluator()->evaluate($notDue->id), 'a future run_at must not fire yet');

        $this->assertOnDbClock($due->getConnection(), $due->getTable(), 'last_evaluated_at', $due->id);
    }

    private function evaluator(): ScheduleEvaluator
    {
        return $this->app->make(ScheduleEvaluator::class);
    }

    private function everyTenMinutes(string $missedPolicy): Schedule
    {
        return Schedule::create([
            'name' => 'clock-'.bin2hex(random_bytes(3)),
            'job_class' => 'App\\Jobs\\Tick',
            'kind' => 'recurring',
            'cron_expression' => '*/10 * * * *',
            'timezone' => 'UTC',
            'enabled' => true,
            'missed_policy' => $missedPolicy,
            'overlap_policy' => 'allow',
        ]);
    }

    /** Seed last_evaluated_at $secondsAgo in the DB's own frame — the frame the evaluator writes. */
    private function seedLastEvaluated(Schedule $schedule, int $secondsAgo): void
    {
        $conn = $schedule->getConnection();
        $conn->table($schedule->getTable())
            ->where('id', $schedule->id)
            ->update(['last_evaluated_at' => $conn->raw(SqlTime::nowMinus($conn, $secondsAgo))]);
    }

    /** Read the stored value's true epoch (never the loaded Carbon) and compare to the DB clock. */
    private function assertOnDbClock(Connection $conn, string $table, string $column, string $id): void
    {
        $ms = $conn->selectOne(
            'SELECT '.SqlTime::epochMsExpr($conn, $column)." AS ms FROM {$table} WHERE id = ?",
            [$id]
        )->ms;

        $drift = abs((int) round(((float) $ms) / 1000) - SqlTime::now($conn)->getTimestamp());

        $this->assertLessThanOrEqual(
            self::TOLERANCE_SECONDS,
            $drift,
            "{$table}.{$column} drifted {$drift}s from the DB clock — it was written in the app timezone"
        );
    }
}
