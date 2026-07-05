<?php

declare(strict_types=1);

namespace JobWarden\Tests\Http;

use JobWarden\JobWarden;
use JobWarden\Http\Livewire\Shell\Sidebar;
use JobWarden\Models\Job;
use JobWarden\Models\Worker;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Livewire\Livewire;

final class DashboardShellTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
        JobWarden::auth(fn ($request) => true);
    }

    public function test_the_gate_guards_the_dashboard_route(): void
    {
        JobWarden::auth(fn ($request) => false);
        $this->get('jobwarden')->assertForbidden();

        JobWarden::auth(fn ($request) => true);
        $this->get('jobwarden')->assertOk()->assertSee('Overview');
    }

    public function test_all_dashboard_routes_resolve(): void
    {
        $job = Job::create(['job_class' => 'X', 'state' => JobState::Queued]);
        $batch = $this->app->make(JobWarden::class)->batch('b')->add('a', 'JobA')->dispatch();
        $schedule = $this->app->make(JobWarden::class)->scheduleCommand('s', '0 3 * * *', 'cache:prune');

        $this->get(route('jobwarden.overview'))->assertOk();
        $this->get(route('jobwarden.jobs'))->assertOk();
        $this->get(route('jobwarden.jobs.show', $job->id))->assertOk();
        $this->get(route('jobwarden.batches'))->assertOk();
        $this->get(route('jobwarden.batches.show', $batch->id))->assertOk();
        $this->get(route('jobwarden.schedules'))->assertOk();
        $this->get(route('jobwarden.schedules.show', $schedule->id))->assertOk();
        $this->get(route('jobwarden.workers'))->assertOk();
    }

    public function test_sidebar_shows_a_red_attention_badge_for_failed_and_orphaned(): void
    {
        Job::create(['job_class' => 'X', 'state' => JobState::Failed]);
        Job::create(['job_class' => 'Y', 'state' => JobState::Orphaned]);
        Job::create(['job_class' => 'Z', 'state' => JobState::Queued]);

        Livewire::test(Sidebar::class)
            ->assertSeeHtml('sb-badge red')
            ->assertSeeHtml('>2</span>');
    }

    public function test_sidebar_reports_fleet_health(): void
    {
        Worker::create(['role' => 'supervisor', 'host_id' => 'h1', 'state' => 'active', 'incarnation' => 1]);
        Worker::create(['role' => 'supervisor', 'host_id' => 'h2', 'state' => 'dead', 'incarnation' => 1]);

        Livewire::test(Sidebar::class)->assertSee('1 dead supervisor');
    }
}
