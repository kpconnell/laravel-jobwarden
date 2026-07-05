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
    public bool $showCreate = false;

    public string $name = '';

    public string $cron = '';

    public string $timezone = 'UTC';

    public string $type = 'command';

    public string $command = '';

    public string $job_class = '';

    public bool $idempotent = false;

    public string $missed_policy = 'run_latest';

    public string $overlap_policy = 'skip';

    public function toggle(string $id): void
    {
        $s = Schedule::findOrFail($id);
        $s->enabled = ! $s->enabled;
        $s->save();
        $this->dispatch('jw-toast', message: $s->name.($s->enabled ? ' enabled' : ' disabled'));
    }

    public function create(JobWarden $jobwarden): void
    {
        $this->validate([
            'name' => 'required|string',
            'cron' => ['required', 'string', fn ($a, $v, $fail) => CronExpression::isValidExpression($v) ?: $fail('Invalid cron expression.')],
            'timezone' => 'required|timezone',
            'type' => 'required|in:command,job',
            'command' => 'required_if:type,command',
            'job_class' => 'required_if:type,job',
            'missed_policy' => 'required|in:run_latest,run_all,skip,coalesce',
            'overlap_policy' => 'required|in:skip,allow,queue',
        ]);

        $options = [
            'idempotent' => $this->idempotent,
            'timezone' => $this->timezone,
            'missed_policy' => $this->missed_policy,
            'overlap_policy' => $this->overlap_policy,
        ];

        try {
            $this->type === 'command'
                ? $jobwarden->scheduleCommand($this->name, $this->cron, $this->command, [], $options)
                : $jobwarden->schedule($this->name, $this->cron, $this->job_class, [], $options);

            $this->reset('name', 'cron', 'timezone', 'command', 'job_class', 'idempotent', 'missed_policy', 'overlap_policy', 'showCreate');
            $this->dispatch('jw-toast', message: 'schedule created');
        } catch (Throwable $e) {
            $this->addError('name', $e->getMessage());
        }
    }

    public function render()
    {
        $schedules = Schedule::query()->orderBy('name')->withDisplayEpochs()->get();

        return view('jobwarden::livewire.schedules', [
            'schedules' => $schedules,
            'enabledCount' => $schedules->where('enabled', true)->count(),
        ]);
    }
}
