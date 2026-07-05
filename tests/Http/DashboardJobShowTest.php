<?php

declare(strict_types=1);

namespace JobWarden\Tests\Http;

use JobWarden\JobWarden;
use JobWarden\Http\Livewire\JobLogTail;
use JobWarden\Http\Livewire\JobShow;
use JobWarden\Models\Job;
use JobWarden\Models\JobAttempt;
use JobWarden\Models\JobLog;
use JobWarden\States\AttemptState;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Livewire\Livewire;

final class DashboardJobShowTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
        JobWarden::auth(fn ($request) => true);
    }

    public function test_renders_params_tags_and_last_error(): void
    {
        config(['jobwarden.search.promoted_params' => ['storeid']]);
        $job = app(JobWarden::class)->dispatch('App\\Jobs\\ReportJob', ['storeid' => 'AMAZ', 'window' => 3]);
        $job->forceFill(['last_error' => [
            'class' => 'App\\Exceptions\\AdvApiNotConfiguredException',
            'message' => 'Store TUFF is not configured',
            'file' => '/app/app/Services/AdvApiService.php:110',
            'trace' => ['#0 frame-one', '#1 frame-two'],
        ]])->saveQuietly();

        Livewire::test(JobShow::class, ['job' => $job->id])
            ->assertOk()
            ->assertSee('storeid')
            ->assertSee('AMAZ')
            ->assertSee('window')
            ->assertSee('last_error')
            ->assertSee('Store TUFF is not configured')
            ->assertSee('AdvApiService.php:110');
    }

    public function test_action_buttons_are_gated_by_state(): void
    {
        $failed = Job::create(['job_class' => 'F', 'state' => JobState::Failed]);
        Livewire::test(JobShow::class, ['job' => $failed->id])
            ->assertSeeHtml('wire:click="retry"')
            ->assertDontSeeHtml('wire:click="cancel"')
            ->assertDontSeeHtml('wire:click="restart"');

        $queued = Job::create(['job_class' => 'Q', 'state' => JobState::Queued]);
        Livewire::test(JobShow::class, ['job' => $queued->id])
            ->assertSeeHtml('wire:click="cancel"')
            ->assertSeeHtml('wire:click="stop"')
            ->assertDontSeeHtml('wire:click="retry"');

        $orphaned = Job::create(['job_class' => 'O', 'state' => JobState::Orphaned]);
        Livewire::test(JobShow::class, ['job' => $orphaned->id])
            ->assertSeeHtml('wire:click="restart"');
    }

    public function test_cancel_action_cancels_a_queued_job(): void
    {
        $job = Job::create(['job_class' => 'X', 'state' => JobState::Queued]);

        Livewire::test(JobShow::class, ['job' => $job->id])
            ->assertOk()
            ->call('cancel')
            ->assertDispatched('jw-toast');

        $this->assertSame(JobState::Canceled, $job->refresh()->state);
    }

    public function test_restart_action_requeues_a_stopped_job(): void
    {
        $job = Job::create(['job_class' => 'X', 'state' => JobState::Stopped]);

        Livewire::test(JobShow::class, ['job' => $job->id])
            ->call('restart')
            ->assertDispatched('jw-toast');

        $this->assertSame(JobState::Queued, $job->refresh()->state);
    }

    public function test_retry_action_requeues_a_failed_job(): void
    {
        $job = Job::create(['job_class' => 'X', 'state' => JobState::Failed]);

        Livewire::test(JobShow::class, ['job' => $job->id])->call('retry');

        $this->assertSame(JobState::Queued, $job->refresh()->state);
    }

    public function test_tabs_switch_and_invalid_tabs_fall_back_to_logs(): void
    {
        $job = Job::create(['job_class' => 'X', 'state' => JobState::Queued]);
        JobAttempt::create(['job_id' => $job->id, 'attempt_number' => 1, 'state' => AttemptState::Failed, 'fencing_token' => 1, 'hostname' => 'host-a', 'exit_code' => 1]);

        Livewire::test(JobShow::class, ['job' => $job->id])
            ->assertSet('tab', 'logs')
            ->set('tab', 'attempts')
            ->assertSee('host-a')
            ->set('tab', 'nonsense')
            ->assertSet('tab', 'logs');
    }

    public function test_result_tab_shows_json_only_for_a_succeeded_job(): void
    {
        $done = Job::create(['job_class' => 'X', 'state' => JobState::Succeeded, 'result' => ['rows' => 42]]);
        Livewire::test(JobShow::class, ['job' => $done->id])
            ->set('tab', 'result')
            ->assertSee('"rows": 42');

        $failed = Job::create(['job_class' => 'Y', 'state' => JobState::Failed, 'result' => null]);
        Livewire::test(JobShow::class, ['job' => $failed->id])
            ->set('tab', 'result')
            ->assertSee('No result to show.');
    }

    // ---- log tail -----------------------------------------------------------

    private function makeLog(Job $job, JobAttempt $attempt, int $seq, string $body): JobLog
    {
        return JobLog::create([
            'job_id' => $job->id, 'attempt_id' => $attempt->id, 'seq' => $seq,
            'ts' => now(), 'level' => 'info', 'body_sink' => 'database', 'body_ref' => $body,
        ]);
    }

    public function test_log_tail_renders_lines_and_tracks_the_cursor(): void
    {
        $job = Job::create(['job_class' => 'X', 'state' => JobState::Running]);
        $attempt = JobAttempt::create(['job_id' => $job->id, 'attempt_number' => 1, 'state' => AttemptState::Running, 'fencing_token' => 1]);
        $log = $this->makeLog($job, $attempt, 1, 'first line');

        Livewire::test(JobLogTail::class, ['jobId' => $job->id])
            ->assertSee('first line')
            ->assertSet('cursor', (int) $log->id);
    }

    public function test_log_tail_poll_picks_up_new_lines(): void
    {
        $job = Job::create(['job_class' => 'X', 'state' => JobState::Running]);
        $attempt = JobAttempt::create(['job_id' => $job->id, 'attempt_number' => 1, 'state' => AttemptState::Running, 'fencing_token' => 1]);
        $this->makeLog($job, $attempt, 1, 'first line');

        $tail = Livewire::test(JobLogTail::class, ['jobId' => $job->id]);
        $before = $tail->get('cursor');

        // Nothing new: the poll skips rendering and the cursor stays put.
        $tail->call('poll')->assertSet('cursor', $before);

        $new = $this->makeLog($job, $attempt, 2, 'a fresh line');
        $tail->call('poll')
            ->assertSee('a fresh line')
            ->assertSet('cursor', (int) $new->id);
    }

    public function test_log_tail_live_toggle_removes_the_poll(): void
    {
        $job = Job::create(['job_class' => 'X', 'state' => JobState::Running]);

        Livewire::test(JobLogTail::class, ['jobId' => $job->id])
            ->assertSeeHtml('wire:poll.2s')
            ->call('toggleLive')
            ->assertSet('live', false)
            ->assertDontSeeHtml('wire:poll.2s');
    }
}
