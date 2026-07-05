<?php

declare(strict_types=1);

namespace JobWarden\Tests\Http;

use JobWarden\JobWarden;
use JobWarden\Http\Livewire\Overview;
use JobWarden\Models\Job;
use JobWarden\Models\JobEvent;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Livewire\Livewire;

final class DashboardOverviewTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
        JobWarden::auth(fn ($request) => true);
    }

    public function test_kpi_tiles_render_the_state_counts(): void
    {
        Job::create(['job_class' => 'X', 'state' => JobState::Running]);
        Job::create(['job_class' => 'Y', 'state' => JobState::Running]);
        Job::create(['job_class' => 'Z', 'state' => JobState::Failed]);

        Livewire::test(Overview::class)
            ->assertOk()
            ->assertSee('Running')
            ->assertSee('Failed')
            ->assertViewHas('states', fn ($states) => (int) $states['running'] === 2 && (int) $states['failed'] === 1);
    }

    public function test_needs_attention_lists_failed_and_orphaned_jobs(): void
    {
        Job::create(['job_class' => 'App\\Jobs\\BoomJob', 'state' => JobState::Failed]);
        Job::create(['job_class' => 'App\\Jobs\\LostJob', 'state' => JobState::Orphaned]);
        Job::create(['job_class' => 'App\\Jobs\\FineJob', 'state' => JobState::Succeeded]);

        Livewire::test(Overview::class)
            ->assertSee('Needs attention')
            ->assertSee('BoomJob')
            ->assertSee('LostJob')
            ->assertViewHas('attention', fn ($attention) => $attention->count() === 2);
    }

    public function test_inline_retry_requeues_a_failed_job(): void
    {
        $job = Job::create(['job_class' => 'X', 'state' => JobState::Failed]);

        Livewire::test(Overview::class)
            ->call('retry', $job->id)
            ->assertDispatched('jw-toast');

        $this->assertSame(JobState::Queued, $job->refresh()->state);
    }

    public function test_inline_restart_requeues_an_orphaned_job(): void
    {
        $job = Job::create(['job_class' => 'X', 'state' => JobState::Orphaned]);

        Livewire::test(Overview::class)->call('restart', $job->id);

        $this->assertSame(JobState::Queued, $job->refresh()->state);
    }

    public function test_activity_feed_shows_event_transitions(): void
    {
        $job = Job::create(['job_class' => 'App\\Jobs\\AuditedJob', 'state' => JobState::Queued]);
        JobEvent::create([
            'job_id' => $job->id, 'level' => 'job', 'from_state' => 'pending', 'to_state' => 'queued',
            'actor_type' => 'operator', 'actor_id' => 'kevin', 'reason' => 'manual requeue', 'created_at' => now(),
        ]);

        Livewire::test(Overview::class)
            ->assertSee('Recent activity')
            ->assertSee('pending → queued')
            ->assertSee('manual requeue');
    }
}
