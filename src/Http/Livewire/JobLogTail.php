<?php

declare(strict_types=1);

namespace JobWarden\Http\Livewire;

use JobWarden\Logging\Contracts\LogBodySink;
use JobWarden\Models\JobLog;
use Livewire\Component;

/**
 * The one streaming surface: a nested component so its 2s poll never
 * re-renders the page around it. The idle poll costs a single limit-1 probe on
 * the (job_id, ts) index and skips rendering when nothing changed; the cursor
 * is job_logs.id (bigint insert order) — `seq` is per-attempt, not per-job.
 */
final class JobLogTail extends Component
{
    /** Rendered window; 5.6M log rows exist in production — never load unbounded. */
    private const WINDOW = 500;

    public string $jobId;

    public bool $live = true;

    public ?int $cursor = null;

    public function toggleLive(): void
    {
        $this->live = ! $this->live;
    }

    /** wire:poll target: re-render only when a new row landed since the cursor. */
    public function poll(): void
    {
        $latest = (int) JobLog::query()->where('job_id', $this->jobId)->max('id');

        if ($latest === (int) $this->cursor) {
            $this->skipRender();
        }
    }

    public function render()
    {
        $sink = app(LogBodySink::class);

        $logs = JobLog::query()->where('job_id', $this->jobId)
            ->orderByDesc('ts')->orderByDesc('id')
            ->limit(self::WINDOW + 1)->withDisplayEpochs()->get();

        $truncated = $logs->count() > self::WINDOW;
        $logs = $logs->take(self::WINDOW)->reverse()->values();

        $this->cursor = (int) $logs->max('id');

        return view('jobwarden::livewire.job-log-tail', [
            'logs' => $logs->map(fn (JobLog $l) => (object) [
                'seq' => $l->seq,
                'ts_ms' => $l->ts_ms,
                'level' => $l->level,
                'step' => $l->step,
                'context' => self::contextLine($l->context),
                'body' => $sink->resolve((string) $l->body_ref),
            ]),
            'truncated' => $truncated,
            'window' => self::WINDOW,
        ]);
    }

    /** logfmt-style `key=value` pairs; values JSON-encoded so strings, bools and arrays stay unambiguous. */
    private static function contextLine(?array $context): ?string
    {
        if (! $context) {
            return null;
        }

        $pairs = [];
        foreach ($context as $key => $value) {
            $pairs[] = $key.'='.json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        return implode(' ', $pairs);
    }
}
