<?php

declare(strict_types=1);

namespace JobWarden\Tests\Http;

use JobWarden\JobWarden;
use JobWarden\Http\Livewire\Workers;
use JobWarden\Models\Job;
use JobWarden\Models\Worker;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Livewire\Livewire;

final class DashboardWorkersTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
        JobWarden::auth(fn ($request) => true);
    }

    public function test_live_workers_show_by_default_and_the_toggle_reveals_the_rest(): void
    {
        Worker::create(['role' => 'supervisor', 'host_id' => 'h1', 'hostname' => 'live-host', 'state' => 'active', 'incarnation' => 1]);
        Worker::create(['role' => 'supervisor', 'host_id' => 'h2', 'hostname' => 'gone-host', 'state' => 'stopped', 'incarnation' => 1]);

        Livewire::test(Workers::class)
            ->assertSee('live-host')
            ->assertDontSee('gone-host')
            ->call('toggleAll')
            ->assertSee('gone-host');
    }

    public function test_dead_supervisors_get_a_warning_chip(): void
    {
        Worker::create(['role' => 'supervisor', 'host_id' => 'h1', 'state' => 'dead', 'incarnation' => 1]);
        Worker::create(['role' => 'scheduler', 'host_id' => 'h1', 'state' => 'dead', 'incarnation' => 1]);

        Livewire::test(Workers::class)
            ->assertSee('dead supervisors')
            ->assertViewHas('deadSupervisors', 1);
    }

    public function test_load_bar_uses_current_load_over_capacity(): void
    {
        Worker::create(['role' => 'supervisor', 'host_id' => 'h1', 'state' => 'active', 'incarnation' => 1, 'capacity' => 10, 'current_load' => 4]);

        Livewire::test(Workers::class)->assertSee('4/10');
    }

    /**
     * The one fleet failure nothing else reports: a supervisor died, nothing
     * restarted it, and its lane's work is simply queueing. Every job is fine,
     * every invariant holds — recovery recovers work, never processes — so the
     * lane going dark has to be surfaced here or it is invisible.
     */
    public function test_a_lane_with_queued_work_and_no_supervisor_is_called_out(): void
    {
        Worker::create(['role' => 'supervisor', 'host_id' => 'h1', 'state' => 'active', 'incarnation' => 1, 'capacity' => 6, 'meta' => ['lane' => 'default']]);
        Job::create(['job_class' => 'X', 'state' => JobState::Queued, 'lane' => 'default']);
        Job::create(['job_class' => 'Y', 'state' => JobState::Queued, 'lane' => 'reports']);

        Livewire::test(Workers::class)
            ->assertSee('no live supervisor for reports')
            ->assertViewHas('laneCoverage', fn (array $lanes): bool => $lanes === [
                ['lane' => 'default', 'supervisors' => 1, 'capacity' => 6, 'queued' => 1],
                ['lane' => 'reports', 'supervisors' => 0, 'capacity' => 0, 'queued' => 1],
            ]);
    }

    public function test_a_covered_lane_raises_no_alarm(): void
    {
        Worker::create(['role' => 'supervisor', 'host_id' => 'h1', 'state' => 'active', 'incarnation' => 1, 'capacity' => 6, 'meta' => ['lane' => 'reports']]);
        Job::create(['job_class' => 'X', 'state' => JobState::Queued, 'lane' => 'reports']);

        Livewire::test(Workers::class)->assertDontSee('no live supervisor');
    }
}
