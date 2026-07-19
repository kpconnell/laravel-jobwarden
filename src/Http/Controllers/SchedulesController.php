<?php

declare(strict_types=1);

namespace JobWarden\Http\Controllers;

use JobWarden\JobWarden;
use JobWarden\Jobs\RunArtisanCommand;
use JobWarden\Models\Schedule;
use Cron\CronExpression;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Read + manage schedules, including creating job- and artisan-command schedules.
 */
final class SchedulesController
{
    public function index(Request $request)
    {
        return Schedule::query()
            ->when($request->filled('enabled'), fn ($q) => $q->where('enabled', $request->boolean('enabled')))
            ->orderBy('name')
            ->paginate(min((int) $request->input('per_page', config('jobwarden.api.pagination', 50)), 200));
    }

    public function show(string $schedule)
    {
        $model = Schedule::findOrFail($schedule);
        $model->setRelation('recent_runs', $model->runs()->orderByDesc('occurrence_time')->limit(25)->get());

        return $model;
    }

    /** Create a schedule — type=command (artisan) or type=job (a JobWardenJob class). */
    public function store(Request $request, JobWarden $jobwarden)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'cron' => ['required', 'string', $this->cronRule()],
            'type' => 'required|in:command,job',
            'command' => 'required_if:type,command|string',
            'job_class' => 'required_if:type,job|string',
            'arguments' => 'array',
            'params' => 'array',
            'idempotent' => 'boolean',
            'max_attempts' => 'integer|min:1',
            'timezone' => 'timezone',
            'missed_policy' => 'in:run_all,run_latest,skip,coalesce',
            'overlap_policy' => 'in:allow,skip,queue',
        ]);

        $options = array_filter([
            'idempotent' => $data['idempotent'] ?? null,
            'max_attempts' => $data['max_attempts'] ?? null,
            'timezone' => $data['timezone'] ?? null,
            'missed_policy' => $data['missed_policy'] ?? null,
            'overlap_policy' => $data['overlap_policy'] ?? null,
        ], static fn ($v) => $v !== null);

        $schedule = $data['type'] === 'command'
            ? $jobwarden->scheduleCommand($data['name'], $data['cron'], $data['command'], $data['arguments'] ?? [], $options)
            : $jobwarden->schedule($data['name'], $data['cron'], $data['job_class'], $data['params'] ?? [], $options);

        return response()->json($schedule, 201);
    }

    /** Toggle enabled, or edit name / target (command or job_class) / cron / timezone / policies / idempotency. */
    public function update(Request $request, string $schedule)
    {
        $model = Schedule::findOrFail($schedule);

        $data = $request->validate([
            'enabled' => 'boolean',
            'name' => 'string',
            'cron_expression' => ['string', $this->cronRule()],
            'timezone' => 'timezone',
            'job_class' => 'string',
            'command' => 'string',
            'idempotent' => 'boolean',
            'max_attempts' => 'nullable|integer|min:1',
            'missed_policy' => 'in:run_all,run_latest,skip,coalesce',
            'overlap_policy' => 'in:allow,skip,queue',
        ]);

        if (array_key_exists('command', $data)) {
            if (($data['job_class'] ?? $model->job_class) !== RunArtisanCommand::class) {
                throw ValidationException::withMessages(['command' => 'command applies only to artisan-command schedules.']);
            }
            $model->params = array_merge((array) $model->params, ['command' => $data['command']]);
            unset($data['command']);
        }

        $model->fill($data)->save();

        return $model->refresh();
    }

    public function destroy(string $schedule)
    {
        Schedule::findOrFail($schedule)->delete();

        return response()->noContent();
    }

    /** Fire the schedule immediately (an ad-hoc run on the scheduled lane). */
    public function run(JobWarden $jobwarden, string $schedule)
    {
        $model = Schedule::findOrFail($schedule);

        $job = $jobwarden->dispatch($model->job_class, (array) ($model->params ?? []), [
            'lane' => 'scheduled',
            'schedule_id' => $model->id,
            'idempotent' => (bool) $model->idempotent,
            'max_attempts' => (int) ($model->max_attempts ?? ($model->idempotent ? 3 : 1)),
            'name' => $model->name,
        ]);

        return response()->json($job, 201);
    }

    private function cronRule(): callable
    {
        return static function (string $attribute, mixed $value, callable $fail): void {
            if (! CronExpression::isValidExpression((string) $value)) {
                $fail('The :attribute is not a valid cron expression.');
            }
        };
    }
}
