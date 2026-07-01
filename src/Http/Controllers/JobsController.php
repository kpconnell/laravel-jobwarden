<?php

declare(strict_types=1);

namespace JobWarden\Http\Controllers;

use JobWarden\JobWarden;
use JobWarden\Contracts\JobWardenJob;
use JobWarden\Models\Job;
use JobWarden\Models\JobLog;
use JobWarden\Operations\OperatorActions;
use Illuminate\Http\Request;

/**
 * Read + operator actions over jobs. Returns Eloquent models/paginators directly
 * — casts already give enum values + ISO dates, and paginate() supplies the meta.
 * No resources, no envelopes.
 */
final class JobsController
{
    /** Dispatch one ad-hoc JobWarden job through the gated operator API. */
    public function store(Request $request, JobWarden $jobwarden)
    {
        $data = $this->dispatchData($request);

        $job = $jobwarden->dispatch($data['job_class'], $data['params'] ?? [], $this->dispatchOptions($data, $request));

        return response()->json($job, 201);
    }

    public function index(Request $request)
    {
        return Job::query()
            ->when($request->filled('state'), fn ($q) => $q->whereIn('state', (array) $request->input('state')))
            ->when($request->filled('lane'), fn ($q) => $q->where('lane', $request->input('lane')))
            ->when($request->filled('name'), fn ($q) => $q->where('name', $request->input('name')))
            ->when($request->filled('created_by'), fn ($q) => $q->where('created_by', $request->input('created_by')))
            ->when($request->filled('batch_id'), fn ($q) => $q->where('batch_id', $request->input('batch_id')))
            ->when($request->filled('schedule_id'), fn ($q) => $q->where('schedule_id', $request->input('schedule_id')))
            ->when($request->filled('q'), fn ($q) => $q->where('job_class', 'like', '%'.$request->input('q').'%'))
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request));
    }

    public function show(string $job)
    {
        return Job::with(['attempts', 'events', 'artifacts'])->findOrFail($job);
    }

    /** Tail a job's logs by a monotonic id cursor (?after=N) — drives the live tail. */
    public function logs(Request $request, string $job)
    {
        return JobLog::query()
            ->where('job_id', $job)
            ->when($request->filled('after'), fn ($q) => $q->where('id', '>', (int) $request->input('after')))
            ->orderBy('id')
            ->limit((int) $request->input('limit', 200))
            ->get();
    }

    public function cancel(Request $request, OperatorActions $ops, string $job)
    {
        return $this->act($request, fn (Job $j) => $ops->cancel($j, $this->reason($request, 'canceled via API'), $this->actor($request)), $job);
    }

    public function stop(Request $request, OperatorActions $ops, string $job)
    {
        return $this->act($request, fn (Job $j) => $ops->stop($j, $this->reason($request, 'stopped via API'), $this->actor($request)), $job);
    }

    public function retry(Request $request, OperatorActions $ops, string $job)
    {
        return $this->act($request, fn (Job $j) => $ops->retry($j, $this->reason($request, 'retried via API'), $this->actor($request)), $job);
    }

    public function restart(Request $request, OperatorActions $ops, string $job)
    {
        return $this->act($request, fn (Job $j) => $ops->restart($j, $this->reason($request, 'restarted via API'), $this->actor($request)), $job);
    }

    private function act(Request $request, callable $action, string $job)
    {
        $model = Job::findOrFail($job);
        $action($model);

        return Job::findOrFail($job); // fresh state
    }

    private function perPage(Request $request): int
    {
        return min((int) $request->input('per_page', config('jobwarden.api.pagination', 50)), 200);
    }

    private function reason(Request $request, string $default): string
    {
        return (string) $request->input('reason', $default);
    }

    private function actor(Request $request): string
    {
        return (string) ($request->user()?->getAuthIdentifier() ?? 'api');
    }

    /** @return array<string,mixed> */
    private function dispatchData(Request $request): array
    {
        return $request->validate([
            'job_class' => ['required', 'string', $this->jobClassRule()],
            'params' => 'array',
            'count' => 'prohibited',
            'lane' => 'string',
            'name' => 'nullable|string',
            'idempotent' => 'boolean',
            'idempotency_key' => 'nullable|string',
            'priority' => 'integer',
            'available_at' => 'date',
            'max_attempts' => 'integer|min:1',
            'max_runtime_sec' => 'nullable|integer|min:1',
            'backoff_strategy' => 'nullable|string',
            'tags' => 'array',
        ]);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function dispatchOptions(array $data, Request $request): array
    {
        return array_filter([
            'lane' => $data['lane'] ?? null,
            'name' => $data['name'] ?? null,
            'idempotent' => $data['idempotent'] ?? null,
            'idempotency_key' => $data['idempotency_key'] ?? null,
            'priority' => $data['priority'] ?? null,
            'available_at' => $data['available_at'] ?? null,
            'max_attempts' => $data['max_attempts'] ?? null,
            'max_runtime_sec' => $data['max_runtime_sec'] ?? null,
            'backoff_strategy' => $data['backoff_strategy'] ?? null,
            'tags' => $data['tags'] ?? null,
            'created_by' => $this->actor($request),
        ], static fn ($value) => $value !== null);
    }

    private function jobClassRule(): callable
    {
        return static function (string $attribute, mixed $value, callable $fail): void {
            if (! is_string($value) || ! class_exists($value) || ! is_subclass_of($value, JobWardenJob::class)) {
                $fail('The :attribute must be a valid JobWarden job class.');
            }
        };
    }
}
