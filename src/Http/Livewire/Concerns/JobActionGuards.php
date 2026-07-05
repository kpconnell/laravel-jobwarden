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
    /** @return list<string> of retry|restart|cancel|stop, in display order */
    public static function allowedActions(Job $job): array
    {
        return match ($job->state) {
            JobState::Failed => ['retry'],
            JobState::Orphaned, JobState::Stopped => ['restart'],
            JobState::Pending, JobState::Queued, JobState::Running, JobState::Retrying => ['cancel', 'stop'],
            default => [],
        };
    }

    /** Who to record in job_events for actions taken from this dashboard session. */
    protected function actor(): string
    {
        return (string) (auth()->id() ?? 'dashboard');
    }
}
