<?php

declare(strict_types=1);

namespace JobWarden\Logging;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;

/**
 * Bridges Laravel's Log facade into the job log: while a child runs, every
 * Log::info()/error()/… call (the handler's and the supervisor wrappers') is
 * captured into jobwarden_job_logs scoped to the running attempt. Because a
 * child runs exactly one job then exits, the listener is left installed for the
 * process lifetime.
 */
final class JobLogCapture
{
    private ?string $jobId = null;

    private ?string $attemptId = null;

    private bool $installed = false;

    public function __construct(private readonly JobLogger $logger)
    {
    }

    public function begin(string $jobId, string $attemptId): void
    {
        $this->jobId = $jobId;
        $this->attemptId = $attemptId;
    }

    public function install(): void
    {
        if ($this->installed) {
            return;
        }
        $this->installed = true;

        Log::listen(function (MessageLogged $event): void {
            if ($this->jobId === null || $this->attemptId === null) {
                return;
            }

            try {
                $this->logger->write(
                    $this->jobId,
                    $this->attemptId,
                    $event->level,
                    (string) $event->message,
                    is_array($event->context) ? $event->context : [],
                    is_array($event->context) ? ($event->context['step'] ?? null) : null,
                );
            } catch (\Throwable) {
                // Logging is best-effort; never let it fail the job.
            }
        });
    }

    public function end(): void
    {
        $this->logger->flush();
        $this->jobId = null;
        $this->attemptId = null;
    }
}
