<?php

declare(strict_types=1);

namespace JobWarden\Search;

use JobWarden\Models\Job;

/**
 * Writes a job's searchable tags at creation time (spec: search is intentional,
 * never inferred). Two sources, merged:
 *
 *   explicit  — the assoc map the dispatcher passed (name => value). Validated
 *               loudly at the dispatch boundary via assertValid(): a bad tag is
 *               the author's bug and must fail where the author can see it.
 *   promoted  — constructor param names opted in via config
 *               jobwarden.search.promoted_params. Promotion NEVER throws: a
 *               listed param whose value isn't a string (or exceeds the column
 *               cap) is simply not promoted — config must not fail a dispatch.
 *
 * Explicit wins on a name collision. Tags are immutable after creation, like
 * the params they mirror; jobwarden:retag re-runs promotion over existing rows
 * (insert-or-ignore) after a config change.
 */
final class TagWriter
{
    public const MAX_NAME = 64;

    public const MAX_VALUE = 200;

    /** @param array<string,string> $explicit */
    public static function write(Job $job, array $explicit = []): void
    {
        $tags = [...self::promoted((array) ($job->params ?? [])), ...$explicit];
        if ($tags === []) {
            return;
        }

        $rows = [];
        foreach ($tags as $name => $value) {
            $rows[] = ['job_id' => $job->getKey(), 'name' => $name, 'value' => $value];
        }

        $job->getConnection()->table(self::table())->insert($rows);
    }

    /**
     * Top-level params whose NAME is opted in and whose value is a string that
     * fits the column. Tag name = param name, verbatim.
     *
     * @param  array<string,mixed>  $params
     * @return array<string,string>
     */
    public static function promoted(array $params): array
    {
        $tags = [];
        foreach ((array) config('jobwarden.search.promoted_params', []) as $name) {
            $value = $params[$name] ?? null;
            if (is_string($value) && $value !== ''
                && mb_strlen($name) <= self::MAX_NAME && mb_strlen($value) <= self::MAX_VALUE) {
                $tags[$name] = $value;
            }
        }

        return $tags;
    }

    /**
     * Validate an explicit tag map at the dispatch boundary: string names ≤ 64,
     * string values ≤ 200, no exceptions made — the caps are the design.
     *
     * @return array<string,string>
     */
    public static function assertValid(mixed $tags): array
    {
        if ($tags === null || $tags === []) {
            return [];
        }
        if (! is_array($tags)) {
            throw new \InvalidArgumentException('tags must be a map of name => string value.');
        }

        foreach ($tags as $name => $value) {
            if (! is_string($name) || $name === '' || mb_strlen($name) > self::MAX_NAME) {
                throw new \InvalidArgumentException(
                    'tag names must be non-empty strings of at most '.self::MAX_NAME.' characters; got '
                    .json_encode($name).'.'
                );
            }
            if (! is_string($value) || $value === '' || mb_strlen($value) > self::MAX_VALUE) {
                throw new \InvalidArgumentException(
                    "tag '{$name}' must have a non-empty string value of at most ".self::MAX_VALUE
                    .' characters; got '.json_encode($value).'.'
                );
            }
        }

        return $tags;
    }

    public static function table(): string
    {
        return ((string) config('jobwarden.table_prefix', 'jobwarden_')).'job_tags';
    }
}
