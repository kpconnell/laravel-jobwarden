<?php

declare(strict_types=1);

namespace JobWarden\States;

/**
 * The actor that caused a state transition, recorded on every job_events row.
 * See architecture spec §3.6 / §4.4.
 */
enum ActorType: string
{
    case Supervisor = 'supervisor';
    case Worker = 'worker';
    case Scheduler = 'scheduler';
    case Reaper = 'reaper';
    case Operator = 'operator';
    case System = 'system';
}
