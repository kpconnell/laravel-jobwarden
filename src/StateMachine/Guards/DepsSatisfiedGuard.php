<?php

declare(strict_types=1);

namespace JobWarden\StateMachine\Guards;

use JobWarden\StateMachine\Contracts\Guard;
use JobWarden\StateMachine\TransitionContext;
use JobWarden\States\JobState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Strict dependency satisfaction (spec §13.6 default): a job is admitted only
 * when ALL of its depends_on jobs have succeeded. A job with no edges passes
 * trivially. Full DAG semantics are fleshed out in P12.
 */
final class DepsSatisfiedGuard implements Guard
{
    public function passes(Model $entity, TransitionContext $context): bool
    {
        $conn = DB::connection(config('jobwarden.connection'));
        $prefix = (string) config('jobwarden.table_prefix');

        $unmet = $conn->table($prefix.'job_dependencies as d')
            ->join($prefix.'jobs as dep', 'dep.id', '=', 'd.depends_on_job_id')
            ->where('d.job_id', $entity->getKey())
            ->where('dep.state', '!=', JobState::Succeeded->value)
            ->count();

        return $unmet === 0;
    }

    public function reason(): string
    {
        return 'dependencies are not all satisfied';
    }
}
