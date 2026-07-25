<?php

declare(strict_types=1);

namespace JobWarden\StateMachine\Guards;

use JobWarden\StateMachine\Contracts\Guard;
use JobWarden\StateMachine\TransitionContext;
use JobWarden\States\BatchState;
use JobWarden\States\JobState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Dependency satisfaction (spec §13.6 default): a job is admitted only when ALL
 * of its depends_on jobs are satisfied — succeeded for an `on_success` edge, or
 * merely ENDED (any terminal state) for an `on_completion` edge, which is what
 * lets a batch carry a finally-style member. The same pair of conditions gates
 * cross-batch edges (spec §8.5): an `on_success` batch dependency requires the
 * upstream BATCH to reach `succeeded`, an `on_completion` one requires it
 * terminal AND quiescent. A job with no edges passes trivially.
 *
 * Admission is evaluated in TWO places and they must agree: here (the guard on
 * the transition itself) and in the Admitter's window query, which pre-filters
 * the candidate rows. BOTH predicates (job-dep and batch-dep) must stay
 * byte-identical between the two sites. Change one and you must change the
 * other — see Admitter::promote().
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

        $terminalBatch = [
            BatchState::Succeeded->value, BatchState::Failed->value, BatchState::Partial->value,
            BatchState::Canceled->value, BatchState::Stopped->value,
        ];

        $unmetBatch = $conn->table($prefix.'job_batch_dependencies as bd')
            ->join($prefix.'batches as b', 'b.id', '=', 'bd.depends_on_batch_id')
            ->where('bd.job_id', $entity->getKey())
            ->where('b.state', '!=', BatchState::Succeeded->value)
            // Same carve-out shape as the job-dep predicate above: strict unless
            // on_completion — and completion means terminal AND quiescent. A
            // failed batch keeps its spared finalizer subtree in flight
            // (terminal != quiescent, see BatchCoordinator::finalizerClosure),
            // and "run after that batch" must wait for that work to drain too.
            // `succeeded` is quiescent by construction and short-circuits above.
            ->where(fn ($q) => $q
                ->where('bd.edge_condition', '!=', 'on_completion')
                ->orWhereNotIn('b.state', $terminalBatch)
                ->orWhereRaw('b.pending_count + b.running_count > 0'))
            ->count();

        return $unmet === 0 && $unmetBatch === 0;
    }

    public function reason(): string
    {
        return 'dependencies are not all satisfied';
    }
}
