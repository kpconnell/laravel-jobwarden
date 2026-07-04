<?php

declare(strict_types=1);

namespace JobWarden\Http\Controllers;

use JobWarden\JobWarden;
use JobWarden\Contracts\JobWardenJob;
use JobWarden\Models\Job;
use JobWarden\Models\JobLog;
use JobWarden\Operations\OperatorActions;
use JobWarden\Search\TagWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read + operator actions over jobs. Returns Eloquent models/paginators directly
 * — casts already give enum values + ISO dates, and paginate() supplies the meta.
 * No resources, no envelopes.
 */
final class JobsController
{
    /**
     * Dispatch through the gated operator API. Accepts either a single job object
     * ({job_class, params, ...}) or a BULK array ({"jobs": [ {...}, {...} ]}). The
     * bulk form pays one HTTP round-trip + one framework bootstrap for the whole
     * batch instead of per job — the difference between feeding a fleet and
     * starving it at scale.
     */
    public function store(Request $request, JobWarden $jobwarden)
    {
        if ($request->has('jobs')) {
            return $this->storeBulk($request, $jobwarden);
        }

        $data = $this->dispatchData($request);

        $job = $jobwarden->dispatch($data['job_class'], $data['params'] ?? [], $this->dispatchOptions($data, $request));

        return response()->json($job, 201);
    }

    /**
     * Bulk dispatch: {"jobs": [ {job_class, params, idempotent, ...}, ... ]}.
     * All rows are created in one transaction, so the batch is atomic — a bad
     * spec rolls the whole request back rather than half-creating it. Only
     * job_class is strictly validated (it gates arbitrary class resolution); the
     * remaining per-job options are read defensively, exactly as the single path.
     */
    private function storeBulk(Request $request, JobWarden $jobwarden)
    {
        $max = (int) config('jobwarden.api.max_bulk', 2000);

        $request->validate([
            'jobs' => "required|array|min:1|max:{$max}",
            'jobs.*.job_class' => ['required', 'string', $this->jobClassRule()],
            'jobs.*.params' => 'array',
            'jobs.*.tags' => ['nullable', 'array', $this->tagsRule()],
        ]);

        $specs = (array) $request->input('jobs');

        $ids = DB::connection(config('jobwarden.connection'))->transaction(
            function () use ($specs, $jobwarden, $request): array {
                $ids = [];
                foreach ($specs as $spec) {
                    $job = $jobwarden->dispatch(
                        $spec['job_class'],
                        $spec['params'] ?? [],
                        $this->dispatchOptions($spec, $request)
                    );
                    $ids[] = $job->id;
                }

                return $ids;
            }
        );

        return response()->json(['created' => count($ids), 'ids' => $ids], 201);
    }

    public function index(Request $request)
    {
        return Job::query()
            ->when($request->filled('state'), fn ($q) => $q->whereIn('state', (array) $request->input('state')))
            ->when($request->filled('lane'), fn ($q) => $q->where('lane', $request->input('lane')))
            ->when($request->filled('name'), fn ($q) => $q->where('name', $request->input('name')))
            ->when($request->filled('job_class'), fn ($q) => $q->where('job_class', $request->input('job_class')))
            ->when($request->filled('created_by'), fn ($q) => $q->where('created_by', $request->input('created_by')))
            ->when($request->filled('batch_id'), fn ($q) => $q->where('batch_id', $request->input('batch_id')))
            ->when($request->filled('schedule_id'), fn ($q) => $q->where('schedule_id', $request->input('schedule_id')))
            ->when($request->filled('tag'), function ($q) use ($request) {
                // ?tag[store]=AMAZ&tag[date]=2025-01* — ANDed, trailing * = prefix.
                foreach ((array) $request->input('tag') as $name => $value) {
                    $q->whereTag((string) $name, (string) $value);
                }
            })
            ->when($request->filled('q'), fn ($q) => $q->search((string) $request->input('q')))
            ->orderByDesc('id') // UUIDv7 ⇒ creation order, served by the PK (no filesort)
            ->paginate($this->perPage($request));
    }

    public function show(string $job)
    {
        return Job::with(['attempts', 'events', 'artifacts', 'tags'])->findOrFail($job);
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
            'tags' => ['array', $this->tagsRule()],
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

    /** Surface TagWriter's dispatch-boundary rules as a 422 instead of a 500. */
    private function tagsRule(): callable
    {
        return static function (string $attribute, mixed $value, callable $fail): void {
            try {
                TagWriter::assertValid($value);
            } catch (\InvalidArgumentException $e) {
                $fail($e->getMessage());
            }
        };
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
