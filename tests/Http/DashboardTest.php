<?php

declare(strict_types=1);

namespace JobWarden\Tests\Http;

use JobWarden\JobWarden;
use JobWarden\Http\Livewire\Batches;
use JobWarden\Http\Livewire\JobShow;
use JobWarden\Http\Livewire\Jobs;
use JobWarden\Http\Livewire\Overview;
use JobWarden\Http\Livewire\Schedules;
use JobWarden\Models\Job;
use JobWarden\Models\JobAttempt;
use JobWarden\Models\JobLog;
use JobWarden\Models\Schedule;
use JobWarden\States\AttemptState;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Livewire\Livewire;

final class DashboardTest extends TestCase
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

    public function test_overview_renders_state_counts(): void
    {
        Job::create(['job_class' => 'X', 'state' => JobState::Running, 'lane' => 'default']);

        Livewire::test(Overview::class)->assertOk()->assertSee('Overview')->assertSee('running');
    }

    public function test_jobs_list_filters_by_state(): void
    {
        Job::create(['job_class' => 'Alpha', 'state' => JobState::Queued]);
        Job::create(['job_class' => 'Beta', 'state' => JobState::Failed]);

        Livewire::test(Jobs::class)
            ->set('state', 'failed')
            ->assertSee('Beta')
            ->assertDontSee('Alpha');
    }

    public function test_jobs_list_emits_a_browser_renderable_epoch(): void
    {
        // The dashboard hands the browser the absolute instant as epoch-ms (rendered into the
        // viewer's timezone client-side), never an app-timezone-formatted string. Guard that the
        // <time data-jw-epoch> carries the true epoch of the stored row.
        $at = \Illuminate\Support\Carbon::create(2026, 7, 2, 18, 0, 0, 'UTC');
        Job::create(['job_class' => 'Epochy', 'state' => JobState::Queued, 'created_at' => $at]);

        Livewire::test(Jobs::class)
            ->assertSee('Epochy')
            ->assertSee('data-jw-epoch="'.($at->getTimestamp() * 1000).'"', false);
    }

    public function test_job_detail_cancel_action(): void
    {
        $job = Job::create(['job_class' => 'X', 'state' => JobState::Queued]);

        Livewire::test(JobShow::class, ['job' => $job->id])
            ->assertOk()
            ->call('cancel')
            ->assertSee('cancel requested');

        $this->assertSame(JobState::Canceled, $job->refresh()->state);
    }

    public function test_job_detail_restart_action_requeues_a_stopped_job(): void
    {
        $job = Job::create(['job_class' => 'X', 'state' => JobState::Stopped]);

        Livewire::test(JobShow::class, ['job' => $job->id])
            ->assertOk()
            ->call('restart')
            ->assertSee('restarted');

        $this->assertSame(JobState::Queued, $job->refresh()->state);
    }

    public function test_job_detail_view_all_logs_dialog(): void
    {
        $job = Job::create(['job_class' => 'X', 'state' => JobState::Failed]);
        $attempt = JobAttempt::create(['job_id' => $job->id, 'attempt_number' => 1, 'state' => AttemptState::Failed, 'fencing_token' => 1]);
        JobLog::create(['job_id' => $job->id, 'attempt_id' => $attempt->id, 'seq' => 1, 'ts' => now(), 'level' => 'error', 'body_sink' => 'database', 'body_ref' => 'boom in the dialog']);

        // The dialog is closed on load and opens on demand.
        Livewire::test(JobShow::class, ['job' => $job->id])
            ->assertOk()
            ->assertSet('showAllLogs', false)
            ->call('openLogs')
            ->assertSet('showAllLogs', true)
            ->assertSee('boom in the dialog')
            ->call('closeLogs')
            ->assertSet('showAllLogs', false);
    }

    public function test_create_a_command_schedule_from_the_dashboard(): void
    {
        Livewire::test(Schedules::class)
            ->set('name', 'nightly')
            ->set('cron', '0 3 * * *')
            ->set('type', 'command')
            ->set('command', 'cache:prune')
            ->set('idempotent', true)
            ->call('create')
            ->assertSee('schedule created');

        $this->assertSame(1, Schedule::where('name', 'nightly')->count());
        $this->assertTrue((bool) Schedule::where('name', 'nightly')->value('idempotent'));
    }

    public function test_create_schedule_rejects_a_bad_cron(): void
    {
        Livewire::test(Schedules::class)
            ->set('name', 'bad')->set('cron', 'garbage')->set('type', 'command')->set('command', 'x')
            ->call('create')
            ->assertHasErrors('cron');

        $this->assertSame(0, Schedule::count());
    }

    public function test_toggle_and_run_a_schedule(): void
    {
        $schedule = $this->app->make(JobWarden::class)->scheduleCommand('s', '0 3 * * *', 'cache:prune');

        Livewire::test(Schedules::class)
            ->call('toggle', $schedule->id)
            ->call('runNow', $schedule->id);

        $this->assertFalse((bool) Schedule::find($schedule->id)->enabled);
        $this->assertSame(1, Job::where('schedule_id', $schedule->id)->where('lane', 'scheduled')->count());
    }

    public function test_cancel_a_batch_from_the_dashboard(): void
    {
        $batch = $this->app->make(JobWarden::class)->batch('b')->add('a', 'JobA')->dispatch();

        Livewire::test(Batches::class)
            ->call('cancel', $batch->id)
            ->assertSee('batch canceled');

        $this->assertSame('canceled', $batch->refresh()->state->value);
    }
}
