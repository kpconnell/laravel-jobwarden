<?php

declare(strict_types=1);

namespace JobWarden\Scheduling;

use JobWarden\Models\Schedule;
use Cron\CronExpression;
use Illuminate\Support\Carbon;

/**
 * Computes the occurrences of a schedule in the window (after, now] — in the
 * schedule's timezone, returned as UTC. When `after` (last_evaluated_at) is stale
 * because the scheduler was down, this naturally yields MULTIPLE occurrences:
 * that IS missed-run detection (spec §7.1).
 *
 * Every instant is a PARAMETER, never read off the model: Eloquent's datetime cast
 * serializes through a bare 'Y-m-d H:i:s' string in app.timezone, so a Carbon that
 * survives a set/get round trip is no longer the instant it went in as. The caller
 * (ScheduleEvaluator) resolves them off the DB clock and passes them in.
 */
final class OccurrenceCalculator
{
    private const HARD_CAP = 10_000; // runaway guard

    /**
     * @param  Carbon|null  $runAt  the one-time schedule's run_at, on the DB clock
     * @return Carbon[] occurrence instants in UTC, ascending
     */
    public function occurrences(Schedule $schedule, Carbon $after, Carbon $now, ?Carbon $runAt): array
    {
        if ($schedule->kind === 'one_time') {
            // Fire when run_at is due. The "fire ONCE" guarantee comes from the
            // schedule_runs UNIQUE constraint (a past run_at must not be missed
            // just because last_evaluated_at advanced past it), spec §7.3.
            return ($runAt !== null && $runAt->lessThanOrEqualTo($now)) ? [$runAt->copy()->utc()] : [];
        }

        if (empty($schedule->cron_expression)) {
            return [];
        }

        $cron = new CronExpression($schedule->cron_expression);
        $tz = $schedule->timezone ?: 'UTC';

        $occurrences = [];
        $cursor = $after->copy();
        while (count($occurrences) < self::HARD_CAP) {
            // strictly after $cursor, evaluated in the schedule's timezone
            $next = Carbon::instance($cron->getNextRunDate($cursor, 0, false, $tz))->utc();
            if ($next->greaterThan($now)) {
                break;
            }
            $occurrences[] = $next;
            $cursor = $next;
        }

        return $occurrences;
    }

    /** The next due instant strictly after $now (for surfacing schedules.next_due_at). */
    public function nextDueAfter(Schedule $schedule, Carbon $now, ?Carbon $runAt): ?Carbon
    {
        if ($schedule->kind === 'one_time') {
            return ($runAt !== null && $runAt->greaterThan($now)) ? $runAt->copy()->utc() : null;
        }
        if (empty($schedule->cron_expression)) {
            return null;
        }

        $cron = new CronExpression($schedule->cron_expression);

        return Carbon::instance($cron->getNextRunDate($now, 0, false, $schedule->timezone ?: 'UTC'))->utc();
    }
}
