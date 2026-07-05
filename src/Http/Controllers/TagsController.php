<?php

declare(strict_types=1);

namespace JobWarden\Http\Controllers;

use JobWarden\Models\JobTag;
use Illuminate\Http\Request;

/**
 * Tag discovery for building filter UIs: known tag names, and known values
 * for a given name (with an optional prefix, for typeahead) — so a client
 * can populate a filter without hardcoding the tag vocabulary.
 */
final class TagsController
{
    public function index(Request $request)
    {
        return $request->filled('name')
            ? $this->values($request, (string) $request->input('name'))
            : $this->names($request);
    }

    /** Distinct tag names in use, with how many jobs carry each. */
    private function names(Request $request)
    {
        return JobTag::query()
            ->selectRaw('name, count(*) as job_count')
            ->groupBy('name')
            ->orderBy('name')
            ->limit($this->limit($request, 500))
            ->get();
    }

    /** Distinct values recorded for one tag name, optionally prefix-filtered. */
    private function values(Request $request, string $name)
    {
        return JobTag::query()
            ->where('name', $name)
            ->when(
                $request->filled('value'),
                fn ($q) => $q->where('value', 'like', addcslashes((string) $request->input('value'), '%_\\').'%')
            )
            ->selectRaw('value, count(*) as job_count')
            ->groupBy('value')
            ->orderBy('value')
            ->limit($this->limit($request, 100))
            ->get();
    }

    private function limit(Request $request, int $default): int
    {
        return min((int) $request->input('limit', $default), $default * 5);
    }
}
