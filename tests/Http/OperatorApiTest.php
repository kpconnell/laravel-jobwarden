<?php

declare(strict_types=1);

namespace JobWarden\Tests\Http;

use JobWarden\JobWarden;
use JobWarden\Jobs\RunArtisanCommand;
use JobWarden\Models\Job;
use JobWarden\Models\Schedule;
use JobWarden\Models\Worker;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;

final class OperatorApiTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
        JobWarden::auth(fn ($request) => true); // allow in tests
    }

    public function test_the_gate_blocks_unauthorized_requests(): void
    {
        JobWarden::auth(fn ($request) => false);

        $this->getJson('jobwarden/api/stats')->assertForbidden();
    }

    public function test_stats_returns_counts(): void
    {
        Job::create(['job_class' => 'X', 'state' => JobState::Running, 'lane' => 'default']);
        Job::create(['job_class' => 'X', 'state' => JobState::Queued, 'lane' => 'scheduled']);

        $this->getJson('jobwarden/api/stats')
            ->assertOk()
            ->assertJsonPath('jobs.running', 1)
            ->assertJsonPath('jobs_by_lane.scheduled', 1);
    }

    public function test_jobs_index_filters_by_lane_and_returns_a_paginator(): void
    {
        Job::create(['job_class' => 'A', 'state' => JobState::Queued, 'lane' => 'default', 'name' => 'other', 'created_by' => 'api']);
        Job::create(['job_class' => 'B', 'state' => JobState::Queued, 'lane' => 'scheduled', 'name' => 'run-1', 'created_by' => 'tester']);

        $this->getJson('jobwarden/api/jobs?lane=scheduled&name=run-1&created_by=tester')
            ->assertOk()
            ->assertJsonPath('data.0.job_class', 'B')
            ->assertJsonCount(1, 'data');
    }

    public function test_dispatch_a_job_through_the_api(): void
    {
        $this->postJson('jobwarden/api/jobs', [
            'job_class' => RunArtisanCommand::class,
            'params' => ['command' => 'cache:clear'],
            'idempotent' => true,
            'max_attempts' => 2,
            'priority' => 10,
        ])
            ->assertCreated()
            ->assertJsonPath('job_class', RunArtisanCommand::class)
            ->assertJsonPath('state', JobState::Queued->value)
            ->assertJsonPath('params.command', 'cache:clear')
            ->assertJsonPath('idempotent', true)
            ->assertJsonPath('max_attempts', 2)
            ->assertJsonPath('priority', 10);

        $this->assertDatabaseHas('jobwarden_jobs', [
            'job_class' => RunArtisanCommand::class,
            'state' => JobState::Queued->value,
            'created_by' => 'api',
        ], 'jobwarden');
    }

    public function test_dispatch_rejects_non_jobwarden_job_classes(): void
    {
        $this->postJson('jobwarden/api/jobs', [
            'job_class' => self::class,
        ])->assertStatus(422)->assertJsonValidationErrors('job_class');
    }

    public function test_job_show_includes_attempts(): void
    {
        $job = Job::create(['job_class' => 'X', 'state' => JobState::Succeeded]);

        $this->getJson("jobwarden/api/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('id', $job->id)
            ->assertJsonStructure(['id', 'state', 'attempts', 'events']);
    }

    public function test_cancel_action_cancels_a_queued_job(): void
    {
        $job = Job::create(['job_class' => 'X', 'state' => JobState::Queued]);

        $this->postJson("jobwarden/api/jobs/{$job->id}/cancel", ['reason' => 'no longer needed'])
            ->assertOk()
            ->assertJsonPath('state', JobState::Canceled->value);

        $this->assertSame(JobState::Canceled, $job->refresh()->state);
    }

    public function test_create_a_command_schedule(): void
    {
        $payload = [
            'name' => 'nightly-prune',
            'cron' => '0 3 * * *',
            'type' => 'command',
            'command' => 'cache:prune',
            'idempotent' => true,
        ];

        $this->postJson('jobwarden/api/schedules', $payload)
            ->assertCreated()
            ->assertJsonPath('job_class', RunArtisanCommand::class)
            ->assertJsonPath('idempotent', true);

        $this->assertDatabaseHas('jobwarden_schedules', ['name' => 'nightly-prune'], 'jobwarden');
    }

    public function test_create_schedule_rejects_a_bad_cron(): void
    {
        $this->postJson('jobwarden/api/schedules', [
            'name' => 'bad', 'cron' => 'not-a-cron', 'type' => 'command', 'command' => 'x',
        ])->assertStatus(422);
    }

    public function test_validation_errors_are_json_even_without_an_accept_header(): void
    {
        // A plain form POST (no Accept: application/json) must still get 422 JSON,
        // not a web-form 302 redirect — the API forces JSON.
        $this->post('jobwarden/api/schedules', [
            'name' => 'bad', 'cron' => 'not-a-cron', 'type' => 'command', 'command' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors('cron');
    }

    public function test_toggle_a_schedule_enabled(): void
    {
        $schedule = $this->app->make(JobWarden::class)->scheduleCommand('s', '0 3 * * *', 'cache:prune');

        $this->patchJson("jobwarden/api/schedules/{$schedule->id}", ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('enabled', false);

        $this->assertFalse((bool) Schedule::find($schedule->id)->enabled);
    }

    public function test_run_a_schedule_now(): void
    {
        $schedule = $this->app->make(JobWarden::class)->scheduleCommand('s', '0 3 * * *', 'cache:prune');

        $this->postJson("jobwarden/api/schedules/{$schedule->id}/run")
            ->assertCreated()
            ->assertJsonPath('lane', 'scheduled')
            ->assertJsonPath('schedule_id', $schedule->id);
    }

    public function test_workers_index(): void
    {
        Worker::create(['role' => 'api_test_worker', 'host_id' => 'h', 'state' => 'active', 'pid' => 1, 'started_at' => now(), 'heartbeat_at' => now()]);

        $this->getJson('jobwarden/api/workers?role=api_test_worker')->assertOk()->assertJsonCount(1);
    }
}
