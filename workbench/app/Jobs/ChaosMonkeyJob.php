<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use JobWarden\Contracts\JobWardenJob;
use JobWarden\Runner\JobContext;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Parameter-driven hostile job for chaos tests. One class can succeed noisily,
 * emit raw process output, write artifacts, throw, hard-crash, or resist stop.
 * Parameter names mirror the params keys verbatim (binding is by exact name),
 * hence the snake_case; `lines` defaults per mode (25 logstorm / 8 stderr).
 */
final class ChaosMonkeyJob implements JobWardenJob
{
    public function __construct(
        private readonly string $mode = 'success',
        private readonly string $marker = '',
        private readonly int $sleep_ms = 0,
        private readonly int $lines = 0,
        private readonly int $payload_bytes = 128,
        private readonly int $seconds = 20,
    ) {
    }

    public function handle(JobContext $context): void
    {
        Log::info('chaos job entered', ['step' => 'chaos_start', 'mode' => $this->mode]);

        match ($this->mode) {
            'success' => $this->succeed($context),
            'logstorm' => $this->logstorm($context),
            'artifact' => $this->artifact($context),
            'stderr' => $this->stderr($context),
            'throw' => $this->throw($context),
            'crash' => $this->crash($context),
            'stubborn' => $this->stubborn($context),
            default => throw new RuntimeException("unknown chaos mode [{$this->mode}]"),
        };

        Log::info('chaos job leaving normally', ['step' => 'chaos_done', 'mode' => $this->mode]);
    }

    public function idempotent(): bool
    {
        return true;
    }

    private function succeed(JobContext $context): void
    {
        if ($this->sleep_ms > 0) {
            usleep($this->sleep_ms * 1000);
        }

        $this->mark('success', $context);
    }

    private function logstorm(JobContext $context): void
    {
        $lines = $this->lines > 0 ? $this->lines : 25;
        for ($i = 1; $i <= $lines; $i++) {
            Log::info("chaos logstorm {$i}/{$lines}", ['step' => 'logstorm', 'i' => $i]);
        }

        $this->mark('logstorm', $context);
    }

    private function artifact(JobContext $context): void
    {
        $context->artifact('report', 'chaos-summary', [
            'meta' => [
                'attempt' => $context->attemptNumber,
                'payload' => str_repeat('x', $this->payload_bytes),
            ],
        ]);

        $this->mark('artifact', $context);
    }

    private function stderr(JobContext $context): void
    {
        // php://stderr, NOT the STDERR constant: prefork children close the
        // constants to reclaim fd 2 into the attempt log (docs/JOB-AUTHORING.md).
        $lines = $this->lines > 0 ? $this->lines : 8;
        for ($i = 1; $i <= $lines; $i++) {
            file_put_contents('php://stderr', "CHAOS-STDERR {$context->jobId} {$context->attemptId} {$i}/{$lines}\n");
        }

        $this->mark('stderr', $context);
    }

    private function throw(JobContext $context): void
    {
        Log::warning('chaos throwing intentionally', ['step' => 'throw']);

        throw new RuntimeException('chaos monkey threw on purpose for '.$context->jobId);
    }

    private function crash(JobContext $context): void
    {
        file_put_contents('php://stderr', "CHAOS-SIGKILL {$context->jobId} {$context->attemptId}\n");
        posix_kill((int) getmypid(), 9);
        sleep(30);
    }

    private function stubborn(JobContext $context): void
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function (): void {
                file_put_contents('php://stderr', "CHAOS-IGNORED-SIGTERM\n");
            });
        }

        $until = microtime(true) + $this->seconds;
        while (microtime(true) < $until) {
            @time_nanosleep(0, 200_000_000);
        }

        $this->mark('stubborn-finished', $context);
    }

    private function mark(string $status, JobContext $context): void
    {
        if ($this->marker === '') {
            return;
        }

        file_put_contents($this->marker, "{$status}:{$context->jobId}:{$context->attemptId}\n", FILE_APPEND);
    }
}
