<?php

declare(strict_types=1);

namespace JobWarden\Http\Controllers;

use JobWarden\JobWarden;
use JobWarden\Models\Batch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class BatchesController
{
    public function index(Request $request)
    {
        return Batch::query()
            ->when($request->filled('state'), fn ($q) => $q->whereIn('state', (array) $request->input('state')))
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->input('per_page', config('jobwarden.api.pagination', 50)), 200));
    }

    public function show(string $batch)
    {
        $model = Batch::with('jobs')->findOrFail($batch);

        // Cross-batch dependencies, re-derived from the members' edge rows (the
        // batch-level declaration fans down to the roots at dispatch, so this
        // aggregation is the batch-level view of it).
        $prefix = (string) config('jobwarden.table_prefix');
        $model->setAttribute('upstream_batches', DB::connection(config('jobwarden.connection'))
            ->table($prefix.'job_batch_dependencies as bd')
            ->join($prefix.'batches as ub', 'ub.id', '=', 'bd.depends_on_batch_id')
            ->whereIn('bd.job_id', $model->jobs->pluck('id'))
            ->distinct()
            ->get(['ub.id', 'ub.name', 'ub.state', 'bd.edge_condition']));

        return $model;
    }

    public function cancel(Request $request, JobWarden $jobwarden, string $batch)
    {
        $model = Batch::findOrFail($batch);
        $jobwarden->cancelBatch(
            $model,
            (string) $request->input('reason', 'canceled via API'),
            (string) ($request->user()?->getAuthIdentifier() ?? 'api'),
        );

        return Batch::findOrFail($batch);
    }
}
