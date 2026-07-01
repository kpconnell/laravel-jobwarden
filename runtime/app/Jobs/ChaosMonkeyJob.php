<?php

declare(strict_types=1);

namespace App\Jobs;

use JobWarden\Contracts\JobWardenJob;
use JobWarden\Runner\JobContext;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Parameter-driven hostile job for chaos tests. One class can succeed noisily,
 * emit raw process output, write artifacts, throw, hard-crash, or resist stop.
 */
final class ChaosMonkeyJob implements JobWardenJob
{
    public function handle(JobContext $context): void
    {
        $mode = (string) ($context->params['mode'] ?? 'success');
        $marker = (string) ($context->params['marker'] ?? '');

        Log::info('chaos job entered', ['step' => 'chaos_start', 'mode' => $mode]);

        match ($mode) {
            'success' => $this->succeed($context, $marker),
            'logstorm' => $this->logstorm($context, $marker),
            'artifact' => $this->artifact($context, $marker),
            'stderr' => $this->stderr($context, $marker),
            'throw' => $this->throw($context),
            'crash' => $this->crash($context),
            'stubborn' => $this->stubborn($context, $marker),
            default => throw new RuntimeException("unknown chaos mode [{$mode}]"),
        };

        Log::info('chaos job leaving normally', ['step' => 'chaos_done', 'mode' => $mode]);
    }

    public function idempotent(): bool
    {
        return true;
    }

    private function succeed(JobContext $context, string $marker): void
    {
        $sleepMs = (int) ($context->params['sleep_ms'] ?? 0);
        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }

        $this->mark($marker, 'success', $context);
    }

    private function logstorm(JobContext $context, string $marker): void
    {
        $lines = (int) ($context->params['lines'] ?? 25);
        for ($i = 1; $i <= $lines; $i++) {
            Log::info("chaos logstorm {$i}/{$lines}", ['step' => 'logstorm', 'i' => $i]);
        }

        $this->mark($marker, 'logstorm', $context);
    }

    private function artifact(JobContext $context, string $marker): void
    {
        $context->artifact('report', 'chaos-summary', [
            'meta' => [
                'attempt' => $context->attemptNumber,
                'payload' => str_repeat('x', (int) ($context->params['payload_bytes'] ?? 128)),
            ],
        ]);

        $this->mark($marker, 'artifact', $context);
    }

    private function stderr(JobContext $context, string $marker): void
    {
        $lines = (int) ($context->params['lines'] ?? 8);
        for ($i = 1; $i <= $lines; $i++) {
            fwrite(STDERR, "CHAOS-STDERR {$context->jobId} {$context->attemptId} {$i}/{$lines}\n");
        }

        $this->mark($marker, 'stderr', $context);
    }

    private function throw(JobContext $context): void
    {
        Log::warning('chaos throwing intentionally', ['step' => 'throw']);

        throw new RuntimeException('chaos monkey threw on purpose for '.$context->jobId);
    }

    private function crash(JobContext $context): void
    {
        fwrite(STDERR, "CHAOS-SIGKILL {$context->jobId} {$context->attemptId}\n");
        posix_kill((int) getmypid(), 9);
        sleep(30);
    }

    private function stubborn(JobContext $context, string $marker): void
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function (): void {
                fwrite(STDERR, "CHAOS-IGNORED-SIGTERM\n");
            });
        }

        $seconds = (int) ($context->params['seconds'] ?? 20);
        $until = microtime(true) + $seconds;
        while (microtime(true) < $until) {
            @time_nanosleep(0, 200_000_000);
        }

        $this->mark($marker, 'stubborn-finished', $context);
    }

    private function mark(string $marker, string $status, JobContext $context): void
    {
        if ($marker === '') {
            return;
        }

        file_put_contents($marker, "{$status}:{$context->jobId}:{$context->attemptId}\n", FILE_APPEND);
    }
}
