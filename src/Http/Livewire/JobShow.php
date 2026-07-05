<?php

declare(strict_types=1);

namespace JobWarden\Http\Livewire;

use JobWarden\Http\Livewire\Concerns\JobActionGuards;
use JobWarden\Models\Job;
use JobWarden\Operations\OperatorActions;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

#[Layout('jobwarden::layout')]
final class JobShow extends Component
{
    use JobActionGuards;

    private const TABS = ['logs', 'attempts', 'timeline', 'result'];

    public string $jobId;

    #[Url] public string $tab = 'logs';

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
            $this->dispatch('jw-toast', message: $ok);
        } catch (Throwable $e) {
            $this->dispatch('jw-toast', message: 'action failed', detail: $e->getMessage(), tone: 'error');
        }
    }

    private function job(): Job
    {
        return Job::findOrFail($this->jobId);
    }

    public function render()
    {
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'logs';
        }

        $job = Job::with([
            'tags',
            'attempts' => fn ($q) => $q->orderBy('attempt_number')->withDisplayEpochs(),
            'events' => fn ($q) => $q->orderBy('id')->withDisplayEpochs(),
            'batch:id,name',
            'schedule:id,name',
        ])->withDisplayEpochs()->findOrFail($this->jobId);

        return view('jobwarden::livewire.job-show', [
            'job' => $job,
            'lastAttempt' => $job->attempts->last(),
        ]);
    }
}
