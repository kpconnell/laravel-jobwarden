<?php

declare(strict_types=1);

namespace Workbench\App\Console;

use JobWarden\JobWarden;
use Illuminate\Console\Command;
use Workbench\App\Jobs\ChattyJob;
use Workbench\App\Jobs\ChaosMonkeyJob;
use Workbench\App\Jobs\CrashJob;
use Workbench\App\Jobs\FailingJob;
use Workbench\App\Jobs\FlakyJob;
use Workbench\App\Jobs\MarkerJob;
use Workbench\App\Jobs\StubbornJob;

/**
 * Convenience for manual testing: dispatch sample jobs.
 *
 *   jobwarden:demo:dispatch marker --sleep=20 --count=3
 *   jobwarden:demo:dispatch chaos --mode=logstorm --count=20
 *   jobwarden:demo:dispatch crash
 *   jobwarden:demo:dispatch flaky --fail-until=2
 */
final class DemoDispatchCommand extends Command
{
    protected $signature = 'jobwarden:demo:dispatch
        {kind=marker : marker|fail|crash|flaky|chatty|stubborn|chaos}
        {--sleep=0 : seconds the marker job sleeps}
        {--sleep-ms=0 : milliseconds the chaos success job sleeps}
        {--count=1 : how many to dispatch}
        {--fail-until=1 : flaky job fails this many times first}
        {--steps=5 : chatty job log steps}
        {--delay=1 : chatty job seconds between steps}
        {--duration=20 : stubborn job run seconds}
        {--mode=success : chaos mode: success|logstorm|artifact|stderr|throw|crash|stubborn}
        {--idempotent : mark chaos jobs idempotent, including crash mode}
        {--lines=25 : chaos log/stderr line count}
        {--payload-bytes=128 : chaos artifact payload size}
        {--priority=0 : job priority}
        {--max-attempts=3 : retry budget}';

    protected $description = 'Dispatch sample JobWarden jobs for manual testing.';

    public function handle(JobWarden $jobwarden): int
    {
        $kind = (string) $this->argument('kind');
        $count = (int) $this->option('count');
        $runtime = (string) config('jobwarden.runtime_path');

        for ($i = 0; $i < $count; $i++) {
            $marker = $runtime.'/marker-'.bin2hex(random_bytes(4)).'.txt';

            $job = match ($kind) {
                'fail' => $jobwarden->dispatch(FailingJob::class, ['message' => 'demo failure'], ['idempotent' => false]),
                'crash' => $jobwarden->dispatch(CrashJob::class, ['marker' => $marker], ['idempotent' => false]),
                'flaky' => $jobwarden->dispatch(FlakyJob::class, [
                    'marker' => $marker,
                    'counter' => $runtime.'/counter-'.bin2hex(random_bytes(4)).'.txt',
                    'fail_until' => (int) $this->option('fail-until'),
                ], ['idempotent' => true, 'max_attempts' => (int) $this->option('max-attempts')]),
                'chatty' => $jobwarden->dispatch(ChattyJob::class, [
                    'steps' => (int) $this->option('steps'),
                    'delay' => (int) $this->option('delay'),
                ], ['idempotent' => true]),
                'stubborn' => $jobwarden->dispatch(StubbornJob::class, [
                    'marker' => $marker,
                    'duration' => (int) $this->option('duration'),
                ], ['idempotent' => true, 'max_attempts' => (int) $this->option('max-attempts')]),
                'chaos' => $jobwarden->dispatch(ChaosMonkeyJob::class, [
                    'mode' => (string) $this->option('mode'),
                    'marker' => $marker,
                    'sleep_ms' => (int) $this->option('sleep-ms'),
                    'lines' => (int) $this->option('lines'),
                    'payload_bytes' => (int) $this->option('payload-bytes'),
                    'seconds' => (int) $this->option('duration'),
                ], [
                    'idempotent' => (bool) $this->option('idempotent') || (string) $this->option('mode') !== 'crash',
                    'max_attempts' => (int) $this->option('max-attempts'),
                    'priority' => (int) $this->option('priority'),
                ]),
                default => $jobwarden->dispatch(MarkerJob::class, [
                    'marker' => $marker,
                    'sleep' => (int) $this->option('sleep'),
                ], ['idempotent' => true, 'max_attempts' => (int) $this->option('max-attempts')]),
            };

            $this->info("dispatched {$kind} job {$job->id}");
        }

        return self::SUCCESS;
    }
}
