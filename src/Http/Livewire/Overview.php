<?php

declare(strict_types=1);

namespace JobWarden\Http\Livewire;

use JobWarden\Http\Livewire\Concerns\JobActionGuards;
use JobWarden\Models\Batch;
use JobWarden\Models\Job;
use JobWarden\Models\JobEvent;
use JobWarden\Models\Schedule;
use JobWarden\Models\Worker;
use JobWarden\Operations\OperatorActions;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

#[Layout('jobwarden::layout')]
final class Overview extends Component
{
    use JobActionGuards;

    private const IN_FLIGHT = ['pending', 'queued', 'running', 'retrying'];

    public function retry(OperatorActions $ops, string $id): void
    {
        $this->act(fn () => $ops->retry(Job::findOrFail($id), 'retry via dashboard', $this->actor()), 're-queued');
    }

    public function restart(OperatorActions $ops, string $id): void
    {
        $this->act(fn () => $ops->restart(Job::findOrFail($id), 'restart via dashboard', $this->actor()), 'restarted');
    }

    private function act(callable $action, string $ok): void
    {
        try {
            $action();
            $this->dispatch('jw-toast', message: $ok);
        } catch (Throwable $e) {
            $this->dispatch('jw-toast', message: 'action failed', detail: $e->getMessage(), tone: 'error');
        }
    }

    public function render()
    {
        $states = Job::query()->groupBy('state')->selectRaw('state, count(*) as c')->pluck('c', 'state');

        $inFlight = collect(self::IN_FLIGHT)->sum(fn ($s) => (int) ($states[$s] ?? 0));
        $stateDist = collect([...self::IN_FLIGHT, 'orphaned'])
            ->map(fn ($s) => ['state' => $s, 'count' => (int) ($states[$s] ?? 0)])
            ->filter(fn ($d) => $d['count'] > 0)
            ->values();

        // Orphaned/failed park for an operator; stuck (alive but past its
        // runtime ceiling) is surfaced here too — it is never auto-reaped.
        $attention = Job::query()->whereIn('state', ['orphaned', 'failed'])
            ->orderByDesc('id')->limit(6)->withDisplayEpochs()->get()
            ->concat(Job::query()->stuck()->orderByDesc('id')->limit(3)->withDisplayEpochs()->get()
                ->each(fn (Job $j) => $j->setAttribute('is_stuck', true)));

        return view('jobwarden::livewire.overview', [
            'states' => $states,
            'inFlight' => $inFlight,
            'stateDist' => $stateDist,
            'attention' => $attention,
            'byLane' => Job::query()->groupBy('lane')->selectRaw('lane, count(*) as c')->orderByDesc('c')->pluck('c', 'lane'),
            'workerRoles' => Worker::query()->whereIn('state', ['starting', 'active', 'draining'])
                ->groupBy('role')->selectRaw('role, count(*) as c')->pluck('c', 'role'),
            'batchStates' => Batch::query()->groupBy('state')->selectRaw('state, count(*) as c')->pluck('c', 'state'),
            'activity' => JobEvent::query()->orderByDesc('id')->limit(12)
                ->with('job:id,job_class,name')->withDisplayEpochs()->get(),
        ]);
    }
}
