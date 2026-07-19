<?php

declare(strict_types=1);

namespace JobWarden\Tests\Http;

use JobWarden\JobWarden;
use JobWarden\Http\Livewire\Schedules;
use JobWarden\Http\Livewire\ScheduleShow;
use JobWarden\Models\Job;
use JobWarden\Models\Schedule;
use JobWarden\Models\ScheduleRun;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Livewire\Livewire;

final class DashboardSchedulesTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
        JobWarden::auth(fn ($request) => true);
    }

    public function test_create_a_command_schedule_with_timezone_and_policies(): void
    {
        Livewire::test(Schedules::class)
            ->set('showCreate', true)
            ->set('name', 'nightly')
            ->set('cron', '0 3 * * *')
            ->set('timezone', 'America/New_York')
            ->set('type', 'command')
            ->set('command', 'cache:prune')
            ->set('idempotent', true)
            ->set('missed_policy', 'coalesce')
            ->set('overlap_policy', 'queue')
            ->call('create')
            ->assertDispatched('jw-toast')
            ->assertSet('showCreate', false);

        $schedule = Schedule::where('name', 'nightly')->firstOrFail();
        $this->assertTrue((bool) $schedule->idempotent);
        $this->assertSame('America/New_York', $schedule->timezone);
        $this->assertSame('coalesce', $schedule->missed_policy);
        $this->assertSame('queue', $schedule->overlap_policy);
        $this->assertSame('cache:prune', data_get($schedule->params, 'command'));
    }

    public function test_create_rejects_a_bad_cron(): void
    {
        Livewire::test(Schedules::class)
            ->set('name', 'bad')->set('cron', 'garbage')->set('type', 'command')->set('command', 'x')
            ->call('create')
            ->assertHasErrors('cron');

        $this->assertSame(0, Schedule::count());
    }

    public function test_create_rejects_a_bad_timezone(): void
    {
        Livewire::test(Schedules::class)
            ->set('name', 's')->set('cron', '0 3 * * *')->set('timezone', 'Mars/Olympus')
            ->set('type', 'command')->set('command', 'x')
            ->call('create')
            ->assertHasErrors('timezone');
    }

    public function test_toggle_a_schedule_from_the_list(): void
    {
        $schedule = $this->app->make(JobWarden::class)->scheduleCommand('s', '0 3 * * *', 'cache:prune');

        Livewire::test(Schedules::class)
            ->call('toggle', $schedule->id)
            ->assertDispatched('jw-toast');

        $this->assertFalse((bool) $schedule->refresh()->enabled);
    }

    public function test_run_now_dispatches_into_the_scheduled_lane(): void
    {
        $schedule = $this->app->make(JobWarden::class)->scheduleCommand('s', '0 3 * * *', 'cache:prune');

        Livewire::test(ScheduleShow::class, ['schedule' => $schedule->id])
            ->call('runNow')
            ->assertDispatched('jw-toast');

        $this->assertSame(1, Job::where('schedule_id', $schedule->id)->where('lane', 'scheduled')->count());
    }

    public function test_detail_shows_meta_and_recent_runs(): void
    {
        $schedule = $this->app->make(JobWarden::class)->scheduleCommand('s', '0 3 * * *', 'cache:prune', [], [
            'timezone' => 'UTC', 'missed_policy' => 'run_latest', 'overlap_policy' => 'skip',
        ]);
        ScheduleRun::create([
            'schedule_id' => $schedule->id,
            'occurrence_time' => now()->subHour(),
            'action' => 'skipped',
            'reason' => 'outside catch-up window',
        ]);

        Livewire::test(ScheduleShow::class, ['schedule' => $schedule->id])
            ->assertOk()
            ->assertSee('0 3 * * *')
            ->assertSee('run_latest')
            ->assertSee('skipped')
            ->assertSee('outside catch-up window');
    }

    public function test_edit_updates_name_target_cron_timezone_policies_and_retry_budget(): void
    {
        $schedule = $this->app->make(JobWarden::class)->scheduleCommand('s', '0 3 * * *', 'cache:prune', ['--force' => true]);

        Livewire::test(ScheduleShow::class, ['schedule' => $schedule->id])
            ->call('openEdit')
            ->assertSet('name', 's')
            ->assertSet('cron', '0 3 * * *')
            ->assertSet('timezone', 'UTC')
            ->assertSet('target', 'cache:prune')
            ->set('name', 'nightly-prune')
            ->set('cron', '30 4 * * 1')
            ->set('timezone', 'America/New_York')
            ->set('target', 'cache:prune-stale')
            ->set('idempotent', true)
            ->set('max_attempts', '5')
            ->set('missed_policy', 'coalesce')
            ->set('overlap_policy', 'queue')
            ->call('saveEdit')
            ->assertSet('showEdit', false)
            ->assertDispatched('jw-toast');

        $schedule->refresh();
        $this->assertSame('nightly-prune', $schedule->name);
        $this->assertSame('30 4 * * 1', $schedule->cron_expression);
        $this->assertSame('America/New_York', $schedule->timezone);
        $this->assertSame('cache:prune-stale', data_get($schedule->params, 'command'));
        $this->assertSame(['--force' => true], data_get($schedule->params, 'arguments'));
        $this->assertTrue((bool) $schedule->idempotent);
        $this->assertSame(5, $schedule->max_attempts);
        $this->assertSame('coalesce', $schedule->missed_policy);
        $this->assertSame('queue', $schedule->overlap_policy);
    }

    public function test_edit_updates_the_job_class_of_a_job_schedule(): void
    {
        $schedule = $this->app->make(JobWarden::class)->schedule('reconcile', '0 3 * * *', 'App\\Jobs\\Reconcile');

        Livewire::test(ScheduleShow::class, ['schedule' => $schedule->id])
            ->call('openEdit')
            ->assertSet('target', 'App\\Jobs\\Reconcile')
            ->set('target', 'App\\Jobs\\ReconcileV2')
            ->call('saveEdit')
            ->assertDispatched('jw-toast');

        $this->assertSame('App\\Jobs\\ReconcileV2', $schedule->refresh()->job_class);
    }

    public function test_edit_surfaces_a_rename_collision_on_the_name_field(): void
    {
        $jobwarden = $this->app->make(JobWarden::class);
        $jobwarden->scheduleCommand('taken', '0 3 * * *', 'cache:prune');
        $schedule = $jobwarden->scheduleCommand('s', '0 3 * * *', 'cache:prune');

        Livewire::test(ScheduleShow::class, ['schedule' => $schedule->id])
            ->call('openEdit')
            ->set('name', 'taken')
            ->call('saveEdit')
            ->assertHasErrors('name')
            ->assertSet('showEdit', true);

        $this->assertSame('s', $schedule->refresh()->name);
    }

    public function test_edit_rejects_a_bad_cron_and_saves_nothing(): void
    {
        $schedule = $this->app->make(JobWarden::class)->scheduleCommand('s', '0 3 * * *', 'cache:prune');

        Livewire::test(ScheduleShow::class, ['schedule' => $schedule->id])
            ->call('openEdit')
            ->set('cron', 'garbage')
            ->call('saveEdit')
            ->assertHasErrors('cron')
            ->assertSet('showEdit', true);

        $this->assertSame('0 3 * * *', $schedule->refresh()->cron_expression);
    }

    public function test_delete_redirects_back_to_the_list(): void
    {
        $schedule = $this->app->make(JobWarden::class)->scheduleCommand('s', '0 3 * * *', 'cache:prune');

        Livewire::test(ScheduleShow::class, ['schedule' => $schedule->id])
            ->call('deleteSchedule')
            ->assertRedirect(route('jobwarden.schedules'));

        $this->assertSame(0, Schedule::count());
    }
}
