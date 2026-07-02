<?php

declare(strict_types=1);

namespace JobWarden\Tests\Support;

use JobWarden\JobWarden;
use JobWarden\Models\Job;
use JobWarden\Recovery\Admitter;
use JobWarden\States\JobState;
use JobWarden\Support\SqlTime;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Illuminate\Support\Carbon;
use Workbench\App\Jobs\MarkerJob;

/**
 * Regression for the first production incident's second half: coordination timestamps
 * must be written on the DB clock, not the app clock. `available_at` is compared against
 * CURRENT_TIMESTAMP (claim / admit), so writing it with Carbon::now() under an
 * app.timezone offset from the DB makes delays wrong — a +1h delay on a behind-UTC app
 * still reads as "past" to the DB clock, so the job runs ~an hour early.
 *
 * These run under a deliberately skewed app timezone and need no /proc or machine-id.
 */
final class DbClockConsistencyTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'America/New_York']);
        date_default_timezone_set('America/New_York');

        $this->setUpJobWardenSchema();
    }

    public function test_immediate_dispatch_is_due_against_the_db_clock(): void
    {
        $job = $this->app->make(JobWarden::class)->dispatch(MarkerJob::class, [], ['idempotent' => true]);

        $this->assertSame(JobState::Queued, $job->state);
        $this->assertTrue($this->dueByDbClock($job->id), 'immediate job should be due (available_at <= DB now)');
    }

    public function test_delayed_dispatch_is_not_due_against_the_db_clock(): void
    {
        $job = $this->app->make(JobWarden::class)->dispatch(
            MarkerJob::class,
            [],
            ['idempotent' => true, 'available_at' => Carbon::now()->addHour()]
        );

        $this->assertSame(JobState::Pending, $job->state);
        // The bug: a +1h delay written on the (behind-UTC) app clock still reads as "past"
        // to the DB clock, so the claim would run it ~an hour early. DB-frame available_at
        // makes the delay real.
        $this->assertFalse($this->dueByDbClock($job->id), 'a +1h delayed job must not be due against the DB clock');

        // ...and the admit pass (also DB-clock now) must not promote it early.
        $this->app->make(Admitter::class)->admit();
        $this->assertSame(JobState::Pending, Job::find($job->id)->state, 'admit promoted a not-yet-due job');
    }

    public function test_created_at_is_stamped_from_the_db_clock_not_the_app_timezone(): void
    {
        $job = $this->app->make(JobWarden::class)->dispatch(MarkerJob::class, [], ['idempotent' => true]);

        // created_at drives claim FIFO ordering and the dashboard; like every other JobWarden
        // time column it must land on the DB clock, not the app timezone (America/New_York here).
        // Read the stored value's true epoch — it must sit on the DB clock, not hours off.
        $conn = (new Job)->getConnection();
        $createdMs = (float) $conn->selectOne(
            'SELECT '.SqlTime::epochMsExpr($conn, 'created_at').' AS ms FROM '.(new Job)->getTable().' WHERE id = ?',
            [$job->id]
        )->ms;

        $drift = abs((int) round($createdMs / 1000) - SqlTime::now($conn)->getTimestamp());
        $this->assertLessThanOrEqual(5, $drift, "created_at drifted {$drift}s from the DB clock — it was written on the app timezone");
    }

    private function dueByDbClock(string $id): bool
    {
        $conn = (new Job)->getConnection();
        $table = (new Job)->getTable();
        $row = $conn->selectOne(
            "SELECT CASE WHEN available_at IS NULL OR available_at <= CURRENT_TIMESTAMP THEN 1 ELSE 0 END AS ok"
            ." FROM {$table} WHERE id = ?",
            [$id]
        );

        return (int) ($row->ok ?? 0) === 1;
    }
}
