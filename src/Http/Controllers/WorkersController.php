<?php

declare(strict_types=1);

namespace JobWarden\Http\Controllers;

use JobWarden\Models\Worker;
use Illuminate\Http\Request;

final class WorkersController
{
    public function index(Request $request)
    {
        return Worker::query()
            ->when(! $request->boolean('all'), fn ($q) => $q->whereIn('state', ['starting', 'active', 'draining']))
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->input('role')))
            ->orderBy('role')
            ->orderByDesc('heartbeat_at')
            ->get();
    }
}
