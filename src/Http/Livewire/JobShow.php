<?php

declare(strict_types=1);

namespace JobWarden\Http\Livewire;

use JobWarden\Logging\Contracts\LogBodySink;
use JobWarden\Models\Job;
use JobWarden\Models\JobLog;
use JobWarden\Operations\OperatorActions;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

#[Layout('jobwarden::layout')]
final class JobShow extends Component
{
    /** Inline log preview cap; the "view all" dialog loads up to self::DIALOG_LOG_CAP. */
    private const PREVIEW_LOG_CAP = 300;

    private const DIALOG_LOG_CAP = 5000;

    public string $jobId;

    public ?string $flash = null;

    public bool $showAllLogs = false;

    public function mount(string $job): void
    {
        $this->jobId = $job;
    }

    public function openLogs(): void
    {
        $this->showAllLogs = true;
    }

    public function closeLogs(): void
    {
        $this->showAllLogs = false;
    }

    public function cancel(OperatorActions $ops): void
    {
        $this->run(fn () => $ops->cancel($this->job(), 'cancel via dashboard', $this->actor()), 'cancel requested');
    }

    public function stop(OperatorActions $ops): void
    {
        $this->run(fn () => $ops->stop($this->job(), 'stop via dashboard', $this->actor()), 'stop requested');
    }

    public function retry(OperatorActions $ops): void
    {
        $this->run(fn () => $ops->retry($this->job(), 'retry via dashboard', $this->actor()), 're-queued');
    }

    public function restart(OperatorActions $ops): void
    {
        $this->run(fn () => $ops->restart($this->job(), 'restart via dashboard', $this->actor()), 'restarted');
    }

    private function run(callable $action, string $ok): void
    {
        try {
            $action();
            $this->flash = $ok;
        } catch (Throwable $e) {
            $this->flash = 'error: '.$e->getMessage();
        }
    }

    private function actor(): string
    {
        return (string) (auth()->id() ?? 'dashboard');
    }

    private function job(): Job
    {
        return Job::findOrFail($this->jobId);
    }

    public function render()
    {
        $job = Job::with(['attempts' => fn ($q) => $q->orderBy('attempt_number'), 'events' => fn ($q) => $q->orderBy('id')->withDisplayEpochs()])
            ->withDisplayEpochs()
            ->findOrFail($this->jobId);

        $logs = $this->loadLogs(self::PREVIEW_LOG_CAP);

        // Only pay for the full stream while the dialog is open. Fetch one past the
        // cap so we can tell the operator honestly when it was truncated.
        $allLogs = null;
        $allLogsTruncated = false;
        if ($this->showAllLogs) {
            $allLogs = $this->loadLogs(self::DIALOG_LOG_CAP + 1);
            $allLogsTruncated = $allLogs->count() > self::DIALOG_LOG_CAP;
            $allLogs = $allLogs->take(self::DIALOG_LOG_CAP);
        }

        return view('jobwarden::livewire.job-show', [
            'job' => $job,
            'logs' => $logs,
            'allLogs' => $allLogs,
            'allLogsTruncated' => $allLogsTruncated,
            'dialogLogCap' => self::DIALOG_LOG_CAP,
        ]);
    }

    private function loadLogs(int $limit): Collection
    {
        $sink = app(LogBodySink::class);

        return JobLog::query()->where('job_id', $this->jobId)
            ->orderBy('ts')->orderBy('id')->limit($limit)->withDisplayEpochs()->get()
            ->map(fn (JobLog $l) => (object) [
                'ts' => $l->ts, 'ts_ms' => $l->ts_ms, 'level' => $l->level, 'step' => $l->step,
                'body' => $sink->resolve((string) $l->body_ref),
            ]);
    }
}
