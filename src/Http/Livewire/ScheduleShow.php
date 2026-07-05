<?php

declare(strict_types=1);

namespace JobWarden\Http\Livewire;

use JobWarden\JobWarden;
use JobWarden\Models\Schedule;
use JobWarden\Models\ScheduleRun;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

#[Layout('jobwarden::layout')]
final class ScheduleShow extends Component
{
    public string $scheduleId;

    public function mount(string $schedule): void
    {
        $this->scheduleId = $schedule;
    }

    public function toggle(): void
    {
        $s = $this->schedule();
        $s->enabled = ! $s->enabled;
        $s->save();
        $this->dispatch('jw-toast', message: $s->name.($s->enabled ? ' enabled' : ' disabled'));
    }

    /** Dispatch an immediate run onto the scheduled lane, outside the cron cadence. */
    public function runNow(JobWarden $jobwarden): void
    {
        $s = $this->schedule();

        try {
            $jobwarden->dispatch($s->job_class, (array) ($s->params ?? []), [
                'lane' => 'scheduled', 'schedule_id' => $s->id, 'name' => $s->name,
                'idempotent' => (bool) $s->idempotent,
                'max_attempts' => (int) ($s->max_attempts ?? ($s->idempotent ? 3 : 1)),
            ]);
            $this->dispatch('jw-toast', message: 'dispatched an immediate run of '.$s->name);
        } catch (Throwable $e) {
            $this->dispatch('jw-toast', message: 'action failed', detail: $e->getMessage(), tone: 'error');
        }
    }

    public function deleteSchedule(): void
    {
        $this->schedule()->delete();

        $this->redirectRoute('jobwarden.schedules', navigate: true);
    }

    private function schedule(): Schedule
    {
        return Schedule::findOrFail($this->scheduleId);
    }

    public function render()
    {
        return view('jobwarden::livewire.schedule-show', [
            'schedule' => Schedule::query()->withDisplayEpochs()->findOrFail($this->scheduleId),
            'runs' => ScheduleRun::query()->where('schedule_id', $this->scheduleId)
                ->orderByDesc('occurrence_time')->limit(25)
                ->with('job:id,state,job_class')->withDisplayEpochs()->get(),
        ]);
    }
}
