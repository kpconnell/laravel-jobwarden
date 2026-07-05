<?php

declare(strict_types=1);

namespace JobWarden\Search;

use JobWarden\Models\JobTag;
use Illuminate\Support\Collection;

/**
 * Tag discovery for filter UIs: the names in use (with how many jobs carry
 * each) and the recorded values for one name (optionally prefix-matched, for
 * typeahead). Shared by the operator API and the dashboard so the LIKE
 * escaping and limits cannot drift between the two.
 */
final class TagIndex
{
    /** @return Collection<int, object> rows of {name, job_count} */
    public static function names(int $limit = 500): Collection
    {
        return JobTag::query()
            ->selectRaw('name, count(*) as job_count')
            ->groupBy('name')
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /** @return Collection<int, object> rows of {value, job_count} */
    public static function values(string $name, string $prefix = '', int $limit = 100): Collection
    {
        return JobTag::query()
            ->where('name', $name)
            ->when($prefix !== '', fn ($q) => $q->where('value', 'like', addcslashes($prefix, '%_\\').'%'))
            ->selectRaw('value, count(*) as job_count')
            ->groupBy('value')
            ->orderBy('value')
            ->limit($limit)
            ->get();
    }
}
