<?php

declare(strict_types=1);

namespace JobWarden\Http\Livewire;

use JobWarden\JobWarden;
use JobWarden\Jobs\RunArtisanCommand;
use JobWarden\Models\Schedule;
use JobWarden\Models\ScheduleRun;
use Cron\CronExpression;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

#[Layout('jobwarden::layout')]
final class ScheduleShow extends Component
{
    public string $scheduleId;

    // Edit modal state. Scope mirrors PATCH /schedules/{id}: name, target, cron,
    // timezone, idempotency, retry budget, and the missed/overlap policies. The
    // type is fixed: a command schedule edits its command, a job schedule its class.
    public bool $showEdit = false;

    public string $name = '';

    public string $cron = '';

    public string $timezone = 'UTC';

    /** The artisan command for command schedules, the job class otherwise. */
    public string $target = '';

    public bool $idempotent = false;

    public string $max_attempts = '';

    public string $missed_policy = 'run_latest';

    public string $overlap_policy = 'skip';

    public function mount(string $schedule): void
    {
        $this->scheduleId = $schedule;
    }

    public function openEdit(): void
    {
        $s = $this->schedule();
        $this->name = (string) $s->name;
        $this->cron = (string) $s->cron_expression;
        $this->timezone = (string) $s->timezone;
        $this->target = $s->job_class === RunArtisanCommand::class
            ? (string) data_get($s->params, 'command')
            : (string) $s->job_class;
        $this->idempotent = (bool) $s->idempotent;
        $this->max_attempts = $s->max_attempts === null ? '' : (string) $s->max_attempts;
        $this->missed_policy = (string) $s->missed_policy;
        $this->overlap_policy = (string) $s->overlap_policy;
        $this->resetErrorBag();
        $this->showEdit = true;
    }

    public function saveEdit(): void
    {
        $this->validate([
            'name' => 'required|string',
            'cron' => ['required', 'string', fn ($a, $v, $fail) => CronExpression::isValidExpression($v) ?: $fail('Invalid cron expression.')],
            'timezone' => 'required|timezone',
            'target' => 'required|string',
            'max_attempts' => 'nullable|integer|min:1',
            'missed_policy' => 'required|in:run_latest,run_all,skip,coalesce',
            'overlap_policy' => 'required|in:skip,allow,queue',
        ]);

        $s = $this->schedule()->fill([
            'name' => $this->name,
            'cron_expression' => $this->cron,
            'timezone' => $this->timezone,
            'idempotent' => $this->idempotent,
            'max_attempts' => $this->max_attempts === '' ? null : (int) $this->max_attempts,
            'missed_policy' => $this->missed_policy,
            'overlap_policy' => $this->overlap_policy,
        ]);

        if ($s->job_class === RunArtisanCommand::class) {
            $s->params = array_merge((array) $s->params, ['command' => $this->target]);
        } else {
            $s->job_class = $this->target;
        }

        try {
            $s->save();
        } catch (Throwable $e) {
            // schedules.name is UNIQUE — surface a rename collision on the field.
            $this->addError('name', $e->getMessage());

            return;
        }

        $this->showEdit = false;
        $this->dispatch('jw-toast', message: 'schedule updated');
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
