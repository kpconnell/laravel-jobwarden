<?php

declare(strict_types=1);

namespace JobWarden\Batch;

use JobWarden\Models\Batch;
use JobWarden\Models\JobDependency;
use JobWarden\Models\JobTag;
use JobWarden\States\JobState;

/**
 * Builds the fan-out graph the dashboard draws for a batch: lanes are the
 * independent sub-chains (connected components of the dependency edges),
 * columns are dependency depth. Pure PHP over three indexed queries — no
 * recursive CTEs, so it behaves identically on sqlite/MariaDB/Postgres.
 * Real batches reach ~1,300 members, which is trivial in memory.
 */
final class BatchDag
{
    /**
     * @return array{
     *   lanes: list<array{key: string, label: string, failing: bool,
     *     states: array<string,int>,
     *     nodes: list<array{id: string, label: string, state: string, depth: int, dimmed: bool}>}>,
     *   maxDepth: int,
     *   nodeCount: int,
     *   stages: list<string>,
     * }
     */
    public static function build(Batch $batch): array
    {
        $jobs = $batch->jobs()->get(['id', 'job_class', 'name', 'state']);
        $ids = $jobs->pluck('id')->all();

        $edges = $ids === [] ? collect() : JobDependency::query()
            ->whereIn('job_id', $ids)
            ->get(['job_id', 'depends_on_job_id']);

        // Adjacency restricted to batch members (a dependency can point outside).
        $member = array_flip($ids);
        $parents = $children = [];  // node => list of nodes
        foreach ($edges as $e) {
            if (! isset($member[$e->job_id], $member[$e->depends_on_job_id])) {
                continue;
            }
            $parents[$e->job_id][] = $e->depends_on_job_id;
            $children[$e->depends_on_job_id][] = $e->job_id;
        }

        $depth = self::depths($ids, $parents, $children);
        $lane = self::components($ids, $parents, $children);
        $dimmed = self::downstreamOfFailure($jobs, $children);
        $labels = self::laneLabels($jobs, $lane, $ids);

        $lanes = [];
        foreach ($jobs as $job) {
            $key = $lane[$job->id];
            $state = $job->state->value;
            $lanes[$key] ??= ['key' => $key, 'label' => $labels[$key], 'failing' => false, 'states' => [], 'nodes' => []];
            $lanes[$key]['states'][$state] = ($lanes[$key]['states'][$state] ?? 0) + 1;
            $lanes[$key]['failing'] = $lanes[$key]['failing'] || in_array($state, ['failed', 'orphaned'], true);
            $lanes[$key]['nodes'][] = [
                'id' => $job->id,
                'label' => self::shortClass($job->name ?? $job->job_class),
                'state' => $state,
                'depth' => $depth[$job->id],
                'dimmed' => isset($dimmed[$job->id]),
            ];
        }

        foreach ($lanes as &$l) {
            usort($l['nodes'], fn ($a, $b) => [$a['depth'], $a['label']] <=> [$b['depth'], $b['label']]);
        }
        unset($l);

        // Failing lanes first (that's what the operator came to see), then by label.
        $lanes = array_values($lanes);
        usort($lanes, fn ($a, $b) => [$b['failing'], $a['label']] <=> [$a['failing'], $b['label']]);

        $maxDepth = $depth === [] ? 0 : max($depth);

        return [
            'lanes' => $lanes,
            'maxDepth' => $maxDepth,
            'nodeCount' => count($ids),
            'stages' => self::stageLabels($jobs, $depth, $maxDepth),
        ];
    }

    /**
     * Longest-path depth per node via Kahn's algorithm (iterative, O(V+E)).
     * Nodes on a cycle — impossible for a well-formed batch — keep depth 0.
     *
     * @param  list<string>  $ids
     * @param  array<string, list<string>>  $parents
     * @param  array<string, list<string>>  $children
     * @return array<string, int>
     */
    private static function depths(array $ids, array $parents, array $children): array
    {
        $depth = array_fill_keys($ids, 0);
        $pending = [];
        $queue = [];
        foreach ($ids as $id) {
            $pending[$id] = count($parents[$id] ?? []);
            if ($pending[$id] === 0) {
                $queue[] = $id;
            }
        }

        for ($i = 0; $i < count($queue); $i++) {
            $id = $queue[$i];
            foreach ($children[$id] ?? [] as $child) {
                $depth[$child] = max($depth[$child], $depth[$id] + 1);
                if (--$pending[$child] === 0) {
                    $queue[] = $child;
                }
            }
        }

        return $depth;
    }

    /**
     * Connected components over the undirected edge set — one lane per
     * independent sub-chain; isolated nodes each form their own.
     *
     * @param  list<string>  $ids
     * @param  array<string, list<string>>  $parents
     * @param  array<string, list<string>>  $children
     * @return array<string, string> node id => component root id
     */
    private static function components(array $ids, array $parents, array $children): array
    {
        $root = [];
        foreach ($ids as $id) {
            if (isset($root[$id])) {
                continue;
            }
            // BFS from each unvisited node; the start node names the component.
            $root[$id] = $id;
            $queue = [$id];
            for ($i = 0; $i < count($queue); $i++) {
                foreach ([...($parents[$queue[$i]] ?? []), ...($children[$queue[$i]] ?? [])] as $next) {
                    if (! isset($root[$next])) {
                        $root[$next] = $id;
                        $queue[] = $next;
                    }
                }
            }
        }

        return $root;
    }

    /**
     * Nodes strictly downstream of a failed member — the tail a fail_fast
     * cascade canceled. The view dims them to tell that story.
     *
     * @param  array<string, list<string>>  $children
     * @return array<string, true>
     */
    private static function downstreamOfFailure(\Illuminate\Support\Collection $jobs, array $children): array
    {
        $queue = $jobs->filter(fn ($j) => $j->state === JobState::Failed)->pluck('id')->values()->all();
        $dimmed = [];
        for ($i = 0; $i < count($queue); $i++) {
            foreach ($children[$queue[$i]] ?? [] as $child) {
                if (! isset($dimmed[$child])) {
                    $dimmed[$child] = true;
                    $queue[] = $child;
                }
            }
        }

        return $dimmed;
    }

    /**
     * Human lane labels. If some tag name splits the batch into per-lane values
     * (every member of a lane sharing one value — e.g. storeid), label lanes by
     * that value; otherwise fall back to the lane's root job name/class.
     *
     * @param  array<string, string>  $lane  node id => lane key
     * @param  list<string>  $ids
     * @return array<string, string> lane key => label
     */
    private static function laneLabels(\Illuminate\Support\Collection $jobs, array $lane, array $ids): array
    {
        $byLane = [];
        foreach ($jobs as $job) {
            $byLane[$lane[$job->id]][] = $job;
        }

        $labels = [];
        foreach ($byLane as $key => $members) {
            $first = $members[0];
            $labels[$key] = self::shortClass($first->name ?? $first->job_class);
        }

        if ($ids === []) {
            return $labels;
        }

        // One query for all members' tags; pick the tag name that distinguishes
        // the most lanes (each lane's members agreeing on a single value).
        $tags = JobTag::query()->whereIn('job_id', $ids)->get(['job_id', 'name', 'value']);
        $values = [];   // tag name => lane key => set of values
        foreach ($tags as $tag) {
            $values[$tag->name][$lane[$tag->job_id] ?? ''][$tag->value] = true;
        }

        $bestName = null;
        $bestDistinct = 1;
        foreach ($values as $name => $perLane) {
            $unanimous = array_filter($perLane, fn ($set) => count($set) === 1);
            if (count($unanimous) !== count($byLane)) {
                continue;   // some lane is mixed or missing the tag — not a lane discriminator
            }
            $distinct = count(array_unique(array_map(fn ($set) => array_key_first($set), $unanimous)));
            if ($distinct > $bestDistinct) {
                $bestDistinct = $distinct;
                $bestName = $name;
            }
        }

        if ($bestName !== null) {
            foreach ($values[$bestName] as $key => $set) {
                $labels[$key] = (string) array_key_first($set);
            }
        }

        return $labels;
    }

    /**
     * Column headers: the class short-names seen at each depth.
     *
     * @param  array<string, int>  $depth
     * @return list<string>
     */
    private static function stageLabels(\Illuminate\Support\Collection $jobs, array $depth, int $maxDepth): array
    {
        $names = array_fill(0, $maxDepth + 1, []);
        foreach ($jobs as $job) {
            $names[$depth[$job->id]][self::shortClass($job->job_class)] = true;
        }

        return array_map(function (array $set): string {
            $list = array_keys($set);

            return count($list) > 2 ? $list[0].' +'.(count($list) - 1) : implode(' / ', $list);
        }, $names);
    }

    private static function shortClass(string $name): string
    {
        $pos = strrpos($name, '\\');

        return $pos === false ? $name : substr($name, $pos + 1);
    }
}
