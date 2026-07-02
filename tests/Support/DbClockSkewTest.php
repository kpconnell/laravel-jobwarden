<?php

declare(strict_types=1);

namespace JobWarden\Tests\Support;

use JobWarden\JobWarden;
use JobWarden\Models\Job;
use JobWarden\Recovery\Admitter;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Workbench\App\Jobs\MarkerJob;

/**
 * The acid test for the DB-clock fix: a deliberately mis-configured system where the
 * app timezone, the DB SESSION timezone, and the DB SERVER timezone all differ.
 *
 *   app.timezone     = America/New_York  (behind UTC)   — what Carbon::now() would use
 *   session time_zone = +09:00           (ahead of UTC)  — what CURRENT_TIMESTAMP uses
 *   server time_zone  = the container default (~UTC)     — irrelevant to the compare
 *
 * Because coordination reads + writes go through CURRENT_TIMESTAMP on the SAME
 * connection, available_at is stored and compared in the one session frame, so
 * eligibility is correct regardless of the other two zones. If any path leaked the app
 * clock, the +09:00 vs Eastern gap would make a +1h delay read as long past.
 *
 * Needs a real session timezone → MySQL/MariaDB (sqlite's CURRENT_TIMESTAMP is always UTC).
 */
final class DbClockSkewTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array($this->engine(), ['mysql'], true)) {
            $this->markTestSkipped('session-timezone skew needs MySQL/MariaDB.');
        }

        config(['app.timezone' => 'America/New_York']);
        date_default_timezone_set('America/New_York');

        $this->setUpJobWardenSchema();

        // Force the coordination connection into a session timezone that matches neither
        // the app nor the server.
        DB::connection(config('jobwarden.connection'))->statement("SET time_zone = '+09:00'");
    }

    protected function tearDown(): void
    {
        // Don't leak the +09:00 session onto a pooled connection reused by later tests.
        DB::purge(config('jobwarden.connection'));

        parent::tearDown();
    }

    public function test_eligibility_is_correct_under_three_way_timezone_skew(): void
    {
        $jw = $this->app->make(JobWarden::class);

        $immediate = $jw->dispatch(MarkerJob::class, [], ['idempotent' => true]);
        $delayed = $jw->dispatch(MarkerJob::class, [], ['idempotent' => true, 'available_at' => Carbon::now()->addHour()]);

        $this->assertSame(JobState::Queued, $immediate->state, 'immediate job is queued');
        $this->assertTrue($this->dueByDbClock($immediate->id), 'immediate job is due under the skew');

        $this->assertSame(JobState::Pending, $delayed->state, 'delayed job starts pending');
        $this->assertFalse($this->dueByDbClock($delayed->id), 'a +1h job must NOT read as due under the skew');

        // The admit pass (also DB-clock) must not promote the not-yet-due job.
        $this->app->make(Admitter::class)->admit();
        $this->assertSame(JobState::Pending, Job::find($delayed->id)->state, 'admit promoted a not-yet-due job under the skew');
    }

    private function dueByDbClock(string $id): bool
    {
        $conn = DB::connection(config('jobwarden.connection'));
        $table = (new Job)->getTable();
        $row = $conn->selectOne(
            "SELECT CASE WHEN available_at IS NULL OR available_at <= CURRENT_TIMESTAMP THEN 1 ELSE 0 END AS ok"
            ." FROM {$table} WHERE id = ?",
            [$id]
        );

        return (int) ($row->ok ?? 0) === 1;
    }
}
