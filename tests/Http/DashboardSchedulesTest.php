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

    public function test_delete_redirects_back_to_the_list(): void
    {
        $schedule = $this->app->make(JobWarden::class)->scheduleCommand('s', '0 3 * * *', 'cache:prune');

        Livewire::test(ScheduleShow::class, ['schedule' => $schedule->id])
            ->call('deleteSchedule')
            ->assertRedirect(route('jobwarden.schedules'));

        $this->assertSame(0, Schedule::count());
    }
}
