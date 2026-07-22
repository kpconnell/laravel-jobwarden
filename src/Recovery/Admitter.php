<?php

declare(strict_types=1);

namespace JobWarden\Recovery;

use JobWarden\Models\Job;
use JobWarden\StateMachine\Exceptions\GuardFailedException;
use JobWarden\StateMachine\Exceptions\StaleFencingTokenException;
use JobWarden\StateMachine\StateMachine;
use JobWarden\StateMachine\TransitionContext;
use JobWarden\States\ActorType;
use JobWarden\States\JobState;
use JobWarden\Support\SqlTime;

/**
 * The admit pass (spec §5.4 recovery / §3.3): promotes jobs that have become
 * eligible into `queued` so a worker can claim them — `retrying → queued` once
 * the backoff elapses, and `pending → queued` once deps + available_at are met.
 * Folded into the supervisor's pre-claim step.
 */
final class Admitter
{
    public function __construct(private readonly StateMachine $stateMachine)
    {
    }

    public function admit(int $limit = 200): int
    {
        return $this->promote(JobState::Retrying, $limit) + $this->promote(JobState::Pending, $limit);
    }

    private function promote(JobState $from, int $limit): int
    {
        // Eligibility is evaluated against the DB clock, not the app clock — available_at is
        // stored in the DB's timezone frame (see JobWarden::dispatch / scheduleRetry), so an
        // app-clock comparison would drift under any TZ mismatch. nowExpr honors
        // Carbon::setTestNow() so time-travel tests still exercise the delay.
        $now = SqlTime::nowExpr(Job::query()->getConnection());

        // Priority first: below the window size admission order is invisible (the
        // claim re-sorts `queued` by priority anyway), but when more rows are
        // eligible than the LIMIT, due-time-only ordering would hand every slot to
        // earlier-due low-priority work and park a high-priority job for passes on
        // end. Within a band, earliest due first.
        $query = Job::query()
            ->where('state', $from->value)
            ->where(fn ($q) => $q->whereNull('available_at')->orWhereRaw("available_at <= {$now}"))
            ->orderByDesc('priority')
            ->orderBy('available_at')
            ->limit($limit);

        // The window must contain only admissible rows. Selecting dep-blocked
        // pending jobs would let a chained backlog whose earliest-available
        // members all fail DepsSatisfiedGuard monopolize the LIMIT and starve
        // eligible successors sorted past it (a 20-chain backfill advanced ~2
        // chains at a time). Pending only: Retrying → Queued is not dep-guarded
        // (deps already succeeded before the first run), see JobTransitions.
        if ($from === JobState::Pending) {
            $prefix = (string) config('jobwarden.table_prefix');
            $terminal = [JobState::Succeeded->value, JobState::Failed->value, JobState::Canceled->value, JobState::Stopped->value];
            // MUST stay identical to DepsSatisfiedGuard's unmet-edge predicate:
            // a window that admits rows the guard rejects wastes the LIMIT, and
            // one that rejects rows the guard would admit strands them.
            $query->whereNotExists(fn ($q) => $q
                ->from($prefix.'job_dependencies as d')
                ->join($prefix.'jobs as dep', 'dep.id', '=', 'd.depends_on_job_id')
                ->whereColumn('d.job_id', $prefix.'jobs.id')
                ->where('dep.state', '!=', JobState::Succeeded->value)
                ->where(fn ($q) => $q
                    ->where('d.edge_condition', '!=', 'on_completion')
                    ->orWhereNotIn('dep.state', $terminal)));
        }

        $jobs = $query->get();

        $promoted = 0;
        foreach ($jobs as $job) {
            try {
                $this->stateMachine->applyJobTransition(
                    $job,
                    JobState::Queued,
                    TransitionContext::for(ActorType::System, null, $from === JobState::Retrying ? 'backoff elapsed' : 'admitted')
                );
                $promoted++;
            } catch (GuardFailedException|StaleFencingTokenException) {
                // deps not yet satisfied, or another worker beat us — skip.
            }
        }

        return $promoted;
    }
}
