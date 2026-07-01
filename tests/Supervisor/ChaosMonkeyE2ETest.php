<?php

declare(strict_types=1);

namespace JobWarden\Tests\Supervisor;

use JobWarden\Claim\ClaimDriverFactory;
use JobWarden\JobWarden;
use JobWarden\Logging\JobLogger;
use JobWarden\Models\Job;
use JobWarden\Models\JobArtifact;
use JobWarden\Models\JobAttempt;
use JobWarden\Models\JobLog;
use JobWarden\Operations\OperatorActions;
use JobWarden\Process\Contracts\HostIdentity;
use JobWarden\Process\Contracts\ProcessProbe;
use JobWarden\Process\Pidfile;
use JobWarden\Recovery\Admitter;
use JobWarden\Recovery\RecoveryService;
use JobWarden\StateMachine\StateMachine;
use JobWarden\States\AttemptState;
use JobWarden\States\JobState;
use JobWarden\Stamp\ProcessStampWriter;
use JobWarden\Supervisor\Supervisor;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use JobWarden\Worker\WorkerRegistry;
use Workbench\App\Jobs\ChaosMonkeyJob;

final class ChaosMonkeyE2ETest extends TestCase
{
    use RefreshesJobWardenSchema;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->engine() !== 'pgsql') {
            $this->markTestSkipped('Chaos E2E uses real child processes through the Docker/Postgres stack.');
        }

        $this->root = dirname(__DIR__, 2);

        config([
            'jobwarden.runtime_path' => $this->root.'/workbench/storage/jobwarden',
            'jobwarden.supervisor.run_command' => [PHP_BINARY, $this->root.'/vendor/bin/testbench', 'jobwarden:run'],
            'jobwarden.supervisor.run_cwd' => $this->root,
            'jobwarden.supervisor.graceful_timeout' => 1,
            'jobwarden.retry.backoff.strategy' => 'fixed',
            'jobwarden.retry.backoff.base' => 0,
            'jobwarden.retry.backoff.cap' => 0,
        ]);

        $this->setUpJobWardenSchema();
    }

    public function test_high_concurrency_chaos_mix_reaches_consistent_terminal_states(): void
    {
        $marker = $this->root.'/workbench/storage/jobwarden/chaos-'.bin2hex(random_bytes(4)).'.txt';
        @unlink($marker);

        $jobwarden = $this->app->make(JobWarden::class);
        $jobs = [];

        for ($i = 0; $i < 12; $i++) {
            $jobs[] = $jobwarden->dispatch(ChaosMonkeyJob::class, [
                'mode' => 'success',
                'marker' => $marker,
                'sleep_ms' => ($i % 3) * 20,
            ], ['idempotent' => true, 'max_attempts' => 1, 'priority' => 10]);
        }

        for ($i = 0; $i < 8; $i++) {
            $jobs[] = $jobwarden->dispatch(ChaosMonkeyJob::class, [
                'mode' => 'logstorm',
                'marker' => $marker,
                'lines' => 35,
            ], ['idempotent' => true, 'max_attempts' => 1, 'priority' => 9]);
        }

        for ($i = 0; $i < 6; $i++) {
            $jobs[] = $jobwarden->dispatch(ChaosMonkeyJob::class, [
                'mode' => 'artifact',
                'marker' => $marker,
                'payload_bytes' => 256,
            ], ['idempotent' => true, 'max_attempts' => 1, 'priority' => 8]);
        }

        for ($i = 0; $i < 6; $i++) {
            $jobs[] = $jobwarden->dispatch(ChaosMonkeyJob::class, [
                'mode' => 'stderr',
                'marker' => $marker,
                'lines' => 12,
            ], ['idempotent' => true, 'max_attempts' => 1, 'priority' => 7]);
        }

        for ($i = 0; $i < 8; $i++) {
            $jobs[] = $jobwarden->dispatch(ChaosMonkeyJob::class, [
                'mode' => 'throw',
            ], ['idempotent' => true, 'max_attempts' => 2, 'priority' => 6]);
        }

        for ($i = 0; $i < 6; $i++) {
            $jobs[] = $jobwarden->dispatch(ChaosMonkeyJob::class, [
                'mode' => 'crash',
            ], ['idempotent' => false, 'max_attempts' => 1, 'priority' => 5]);
        }

        for ($i = 0; $i < 4; $i++) {
            $jobs[] = $jobwarden->dispatch(ChaosMonkeyJob::class, [
                'mode' => 'stubborn',
                'marker' => $marker,
                'seconds' => 20,
            ], ['idempotent' => true, 'max_attempts' => 1, 'priority' => 100]);
        }

        $supervisor = $this->supervisor(capacity: 16);
        $supervisor->boot();

        $this->driveChaos($supervisor, array_map(fn (Job $job): string => (string) $job->id, $jobs));

        $this->assertSame(0, Job::whereIn('state', [
            JobState::Pending->value,
            JobState::Queued->value,
            JobState::Retrying->value,
            JobState::Running->value,
            JobState::Orphaned->value,
        ])->count(), 'no job should be left in a live or limbo state');

        $this->assertSame(32, Job::where('state', JobState::Succeeded->value)->count());
        $this->assertSame(14, Job::where('state', JobState::Failed->value)->count());
        $this->assertSame(4, Job::where('state', JobState::Stopped->value)->count());

        $this->assertSame(58, JobAttempt::count(), '8 throw jobs run twice; every other chaos job runs once');
        foreach (Job::all() as $job) {
            $this->assertSame(
                (int) $job->attempt_count,
                JobAttempt::where('job_id', $job->id)->count(),
                'attempt_count should match the actual attempts per job'
            );
        }

        $this->assertSame(0, JobAttempt::whereIn('state', [AttemptState::Dispatched->value, AttemptState::Running->value])->count());
        $this->assertSame(6, JobAttempt::where('term_signal', 9)->where('state', AttemptState::Failed->value)->count());
        $this->assertSame(4, JobAttempt::where('state', AttemptState::Stopped->value)->count());

        $this->assertGreaterThanOrEqual(8 * 35, JobLog::where('step', 'logstorm')->count());
        $this->assertSame(6, JobArtifact::where('type', 'report')->where('name', 'chaos-summary')->count());
        $this->assertSame(6, JobLog::where('step', 'process_output')->where('body_ref', 'like', '%CHAOS-STDERR%')->count());
        $this->assertSame(6, JobLog::where('step', 'process_output')->where('body_ref', 'like', '%CHAOS-SIGKILL%')->count());

        $this->assertSame(0, $this->jobsWithDuplicateAttemptNumbers());
        $this->assertSame(0, $this->attemptsWithGapfulLogSequences());
        $this->assertStringContainsString('success:', (string) @file_get_contents($marker));
        $this->assertStringContainsString('artifact:', (string) @file_get_contents($marker));

        @unlink($marker);
    }

    private function supervisor(int $capacity): Supervisor
    {
        return new Supervisor(
            $this->app->make(ClaimDriverFactory::class),
            $this->app->make(Admitter::class),
            $this->app->make(ProcessStampWriter::class),
            $this->app->make(ProcessProbe::class),
            $this->app->make(WorkerRegistry::class),
            $this->app->make(StateMachine::class),
            $this->app->make(RecoveryService::class),
            $this->app->make(HostIdentity::class),
            $this->app->make(Pidfile::class),
            $this->app->make(JobLogger::class),
            $capacity,
        );
    }

    /** @param string[] $jobIds */
    private function driveChaos(Supervisor $supervisor, array $jobIds): void
    {
        $ops = $this->app->make(OperatorActions::class);
        $stopsRequested = false;
        $deadline = microtime(true) + 45.0;

        while (microtime(true) < $deadline) {
            $supervisor->tick();

            if (! $stopsRequested) {
                $stubborn = Job::where('job_class', ChaosMonkeyJob::class)
                    ->where('params->mode', 'stubborn')
                    ->where('state', JobState::Running->value)
                    ->get();

                if ($stubborn->count() === 4) {
                    foreach ($stubborn as $job) {
                        $ops->stop($job, 'chaos stop stubborn child', 'chaos-test');
                    }
                    $stopsRequested = true;
                }
            }

            $live = Job::whereIn('id', $jobIds)->whereIn('state', [
                JobState::Pending->value,
                JobState::Queued->value,
                JobState::Retrying->value,
                JobState::Running->value,
                JobState::Orphaned->value,
            ])->count();

            if ($live === 0 && $supervisor->load() === 0) {
                return;
            }

            usleep(50_000);
        }

        $states = Job::whereIn('id', $jobIds)->selectRaw('state, count(*) as c')->groupBy('state')->pluck('c', 'state')->all();
        $this->fail('chaos run did not settle; states='.json_encode($states).' load='.$supervisor->load());
    }

    private function jobsWithDuplicateAttemptNumbers(): int
    {
        return (int) $this->jobwarden()->query()
            ->fromSub(
                JobAttempt::query()
                    ->selectRaw('job_id, attempt_number, count(*) as c')
                    ->groupBy('job_id', 'attempt_number')
                    ->havingRaw('count(*) > 1'),
                'dupes'
            )
            ->count();
    }

    private function attemptsWithGapfulLogSequences(): int
    {
        return (int) $this->jobwarden()->query()
            ->fromSub(
                JobLog::query()
                    ->selectRaw('attempt_id, count(*) as c, min(seq) as min_seq, max(seq) as max_seq')
                    ->groupBy('attempt_id')
                    ->havingRaw('count(*) > 0')
                    ->havingRaw('min(seq) <> 1 OR max(seq) <> count(*)'),
                'gapful'
            )
            ->count();
    }
}
