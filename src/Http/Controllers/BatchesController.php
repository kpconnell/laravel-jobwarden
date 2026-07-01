<?php

declare(strict_types=1);

namespace JobWarden\Http\Controllers;

use JobWarden\JobWarden;
use JobWarden\Models\Batch;
use Illuminate\Http\Request;

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
        return Batch::with('jobs')->findOrFail($batch);
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
