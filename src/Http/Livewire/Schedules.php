<?php

declare(strict_types=1);

namespace JobWarden\Http\Livewire;

use JobWarden\JobWarden;
use JobWarden\Models\Schedule;
use Cron\CronExpression;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

#[Layout('jobwarden::layout')]
final class Schedules extends Component
{
    public ?string $flash = null;

    public bool $showCreate = false;

    public string $name = '';
    public string $cron = '';
    public string $type = 'command';
    public string $command = '';
    public string $job_class = '';
    public bool $idempotent = false;

    public function toggle(string $id): void
    {
        $s = Schedule::findOrFail($id);
        $s->enabled = ! $s->enabled;
        $s->save();
        $this->flash = $s->name.($s->enabled ? ' enabled' : ' disabled');
    }

    public function runNow(JobWarden $jobwarden, string $id): void
    {
        $s = Schedule::findOrFail($id);
        $jobwarden->dispatch($s->job_class, (array) ($s->params ?? []), [
            'lane' => 'scheduled', 'schedule_id' => $s->id, 'name' => $s->name,
            'idempotent' => (bool) $s->idempotent,
            'max_attempts' => (int) ($s->max_attempts ?? ($s->idempotent ? 3 : 1)),
        ]);
        $this->flash = 'dispatched an immediate run of '.$s->name;
    }

    public function deleteSchedule(string $id): void
    {
        Schedule::findOrFail($id)->delete();
        $this->flash = 'schedule deleted';
    }

    public function create(JobWarden $jobwarden): void
    {
        $this->validate([
            'name' => 'required|string',
            'cron' => ['required', 'string', fn ($a, $v, $fail) => CronExpression::isValidExpression($v) ?: $fail('Invalid cron expression.')],
            'type' => 'required|in:command,job',
            'command' => 'required_if:type,command',
            'job_class' => 'required_if:type,job',
        ]);

        try {
            $opts = ['idempotent' => $this->idempotent];
            $this->type === 'command'
                ? $jobwarden->scheduleCommand($this->name, $this->cron, $this->command, [], $opts)
                : $jobwarden->schedule($this->name, $this->cron, $this->job_class, [], $opts);

            $this->reset('name', 'cron', 'command', 'job_class', 'idempotent', 'showCreate');
            $this->flash = 'schedule created';
        } catch (Throwable $e) {
            $this->addError('name', $e->getMessage());
        }
    }

    public function render()
    {
        return view('jobwarden::livewire.schedules', [
            'schedules' => Schedule::query()->orderBy('name')->get(),
        ]);
    }
}
