<?php

declare(strict_types=1);

namespace JobWarden\Http\Livewire\Concerns;

use JobWarden\Models\Job;
use JobWarden\States\JobState;

/**
 * The dashboard's mirror of the operator state machine: which actions are
 * offered (and attempted) for a job in a given state. Rendering and bulk
 * dispatch both consult this; the server stays authoritative — OperatorActions
 * re-asserts the state on every call.
 */
trait JobActionGuards
{
    /**
     * One halt verb per state (cancel withdraws pre-run work, stop halts active
     * work), a re-run verb where one applies, and skip everywhere but succeeded
     * — on a running job it is kill-and-skip, so the view words its confirm
     * accordingly.
     *
     * @return list<string> of retry|restart|cancel|stop|skip, in display order
     */
    public static function allowedActions(Job $job): array
    {
        return match ($job->state) {
            JobState::Failed => ['retry', 'skip'],
            JobState::Orphaned => ['restart', 'stop', 'skip'],
            JobState::Stopped => ['restart', 'skip'],
            JobState::Canceled => ['skip'],
            JobState::Pending, JobState::Queued, JobState::Retrying => ['cancel', 'skip'],
            JobState::Running => ['stop', 'skip'],
            default => [],
        };
    }

    /** Who to record in job_events for actions taken from this dashboard session. */
    protected function actor(): string
    {
        return (string) (auth()->id() ?? 'dashboard');
    }
}
