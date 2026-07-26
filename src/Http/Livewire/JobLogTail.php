<?php

declare(strict_types=1);

namespace JobWarden\Http\Livewire;

use Illuminate\Database\Eloquent\Builder;
use JobWarden\Logging\Contracts\LogBodySink;
use JobWarden\Models\Job;
use JobWarden\Models\JobLog;
use JobWarden\States\JobState;
use JobWarden\Support\SqlTime;
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

    /** Time filter: option => minutes back from the newest line (null = no filter). */
    private const WINDOWS = ['all' => null, '5m' => 5, '30m' => 30];

    public string $jobId;

    public bool $live = false;

    public string $since = 'all';

    public ?int $cursor = null;

    /**
     * Live only while the job can still emit. The database sink writes each line
     * inline (never buffered), so a terminal job's log is complete at mount —
     * polling it forever would be a probe per 2s that can never see a new row.
     */
    public function mount(): void
    {
        $state = Job::query()->whereKey($this->jobId)->value('state');

        $this->live = $state instanceof JobState && ! $state->isTerminal();
    }

    public function toggleLive(): void
    {
        $this->live = ! $this->live;
    }

    /**
     * The rendered set: this job's lines, narrowed to the selected window.
     *
     * The window is cut in SQL against the DB clock — the same clock that stamped `ts`
     * (JobLogger writes CURRENT_TIMESTAMP, never a PHP datetime). Comparing to a
     * PHP-side "now" would land the cut wherever app.timezone happened to point, which
     * is the drift that already cost us a production mass-orphaning; the DB never has
     * to agree with the app's zone for this to be right. Range-scans (job_id, ts).
     */
    private function scoped(): Builder
    {
        $query = JobLog::query()->where('job_id', $this->jobId);

        if (($minutes = self::WINDOWS[$this->since] ?? null) !== null) {
            $query->whereRaw('ts >= '.SqlTime::nowMinus($query->getConnection(), $minutes * 60));
        }

        return $query;
    }

    /** wire:poll target: re-render only when what's *shown* changed — a new line, or one aging out. */
    public function poll(): void
    {
        if ((int) $this->scoped()->max('id') === (int) $this->cursor) {
            $this->skipRender();
        }
    }

    public function render()
    {
        if (! array_key_exists($this->since, self::WINDOWS)) {
            $this->since = 'all';
        }

        $sink = app(LogBodySink::class);

        $logs = $this->scoped()
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
            'windows' => self::windowLabels(),
        ]);
    }

    /** @return array<string,string> option value => label */
    private static function windowLabels(): array
    {
        return array_map(fn (?int $m) => $m === null ? 'All' : "Last {$m} min", self::WINDOWS);
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
