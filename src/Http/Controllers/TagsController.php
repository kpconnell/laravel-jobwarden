<?php

declare(strict_types=1);

namespace JobWarden\Http\Controllers;

use JobWarden\Search\TagIndex;
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
        $limit = static fn (int $default): int => min((int) $request->input('limit', $default), $default * 5);

        return $request->filled('name')
            ? TagIndex::values((string) $request->input('name'), (string) $request->input('value', ''), $limit(100))
            : TagIndex::names($limit(500));
    }
}
