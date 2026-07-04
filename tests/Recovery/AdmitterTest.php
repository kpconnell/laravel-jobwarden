<?php

declare(strict_types=1);

namespace JobWarden\Tests\Recovery;

use JobWarden\JobWarden;
use JobWarden\Models\Job;
use JobWarden\Recovery\Admitter;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Illuminate\Support\Carbon;

final class AdmitterTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
    }

    public function test_admit_window_is_priority_first_never_starving_high_priority_behind_earlier_due_rows(): void
    {
        // Fleets routinely have more eligible rows than one pass's LIMIT. Ordered
        // by due time alone, every slot of an over-limit window goes to earlier-due
        // low-priority rows and a high-priority job waits passes on end — so the
        // window is priority-first, due-time within a band (mirroring the claim,
        // whose in-band tiebreak is created_at).
        $t0 = Carbon::parse('2026-07-02 12:00:00');
        Carbon::setTestNow($t0);

        try {
            $warden = $this->app->make(JobWarden::class);

            // The high-priority job is due LAST: due-time ordering alone would
            // admit both low-priority rows ahead of it.
            $low1 = $warden->dispatch('JobLow1', [], ['delay' => 10, 'priority' => 0]);
            $low2 = $warden->dispatch('JobLow2', [], ['delay' => 20, 'priority' => 0]);
            $high = $warden->dispatch('JobHigh', [], ['delay' => 30, 'priority' => 9]);

            Carbon::setTestNow($t0->copy()->addMinute()); // all three now due

            // A window of 1 — an over-limit burst in miniature.
            $this->app->make(Admitter::class)->admit(limit: 1);

            $this->assertSame(JobState::Queued, $this->fresh($high)->state, 'high priority takes the window despite being due last');
            $this->assertSame(JobState::Pending, $this->fresh($low1)->state);
            $this->assertSame(JobState::Pending, $this->fresh($low2)->state);

            // Within a band, earliest due still goes first.
            $this->app->make(Admitter::class)->admit(limit: 1);

            $this->assertSame(JobState::Queued, $this->fresh($low1)->state, 'then earliest-due within the band');
            $this->assertSame(JobState::Pending, $this->fresh($low2)->state);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function fresh(Job $job): Job
    {
        return Job::query()->findOrFail($job->id);
    }
}
