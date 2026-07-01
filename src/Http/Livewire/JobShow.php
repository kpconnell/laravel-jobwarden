<?php

declare(strict_types=1);

namespace JobWarden\Http\Livewire;

use JobWarden\Logging\Contracts\LogBodySink;
use JobWarden\Models\Job;
use JobWarden\Models\JobLog;
use JobWarden\Operations\OperatorActions;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

#[Layout('jobwarden::layout')]
final class JobShow extends Component
{
    public string $jobId;

    public ?string $flash = null;

    public function mount(string $job): void
    {
        $this->jobId = $job;
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
        $job = Job::with(['attempts' => fn ($q) => $q->orderBy('attempt_number'), 'events' => fn ($q) => $q->orderBy('id')])
            ->findOrFail($this->jobId);

        $sink = app(LogBodySink::class);
        $logs = JobLog::query()->where('job_id', $this->jobId)->orderBy('seq')->limit(300)->get()
            ->map(fn (JobLog $l) => (object) [
                'ts' => $l->ts, 'level' => $l->level, 'step' => $l->step,
                'body' => $sink->resolve((string) $l->body_ref),
            ]);

        return view('jobwarden::livewire.job-show', ['job' => $job, 'logs' => $logs]);
    }
}
