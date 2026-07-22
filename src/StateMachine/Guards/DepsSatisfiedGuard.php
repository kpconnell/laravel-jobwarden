<?php

declare(strict_types=1);

namespace JobWarden\StateMachine\Guards;

use JobWarden\StateMachine\Contracts\Guard;
use JobWarden\StateMachine\TransitionContext;
use JobWarden\States\JobState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Dependency satisfaction (spec §13.6 default): a job is admitted only when ALL
 * of its depends_on jobs are satisfied — succeeded for an `on_success` edge, or
 * merely ENDED (any terminal state) for an `on_completion` edge, which is what
 * lets a batch carry a finally-style member. A job with no edges passes
 * trivially.
 *
 * Admission is evaluated in TWO places and they must agree: here (the guard on
 * the transition itself) and in the Admitter's window query, which pre-filters
 * the candidate rows. Change one and you must change the other — see
 * Admitter::promote().
 */
final class DepsSatisfiedGuard implements Guard
{
    public function passes(Model $entity, TransitionContext $context): bool
    {
        $conn = DB::connection(config('jobwarden.connection'));
        $prefix = (string) config('jobwarden.table_prefix');

        $terminal = [JobState::Succeeded->value, JobState::Failed->value, JobState::Canceled->value, JobState::Stopped->value];

        $unmet = $conn->table($prefix.'job_dependencies as d')
            ->join($prefix.'jobs as dep', 'dep.id', '=', 'd.depends_on_job_id')
            ->where('d.job_id', $entity->getKey())
            ->where('dep.state', '!=', JobState::Succeeded->value)
            // Written as a carve-out from the strict rule rather than as two
            // arms, so an unrecognized edge_condition keeps the strict (safe)
            // behavior instead of admitting on anything. `orphaned` is NOT
            // terminal: a parked orphan awaits an operator verdict, so it still
            // gates its dependents under either condition.
            ->where(fn ($q) => $q
                ->where('d.edge_condition', '!=', 'on_completion')
                ->orWhereNotIn('dep.state', $terminal))
            ->count();

        return $unmet === 0;
    }

    public function reason(): string
    {
        return 'dependencies are not all satisfied';
    }
}
