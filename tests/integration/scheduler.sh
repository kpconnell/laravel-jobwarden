#!/usr/bin/env bash
#
# Scheduler acceptance test — REAL, multi-scheduler-safe (spec §7).
#
#   missed-run : a schedule "down" for 20 minutes catches up its missed
#                occurrences on the next evaluation.
#   no-double  : FOUR scheduler processes (host1 + host2) race to evaluate the
#                SAME backlog concurrently; every occurrence materializes AT MOST
#                ONCE (the schedule-row lock + the schedule_runs UNIQUE).
#   end-to-end : a supervisor then runs the enqueued jobs to success.
#
# Run from the repo root on the docker host:  bash tests/integration/scheduler.sh
set -uo pipefail

TB="vendor/bin/testbench"
PASS=0; FAIL=0
ok()  { echo "  ✅ $1"; PASS=$((PASS+1)); }
bad() { echo "  ❌ $1"; FAIL=$((FAIL+1)); }
q() { docker compose exec -T pg psql -U jobwarden -d jobwarden -tAc "$1" 2>/dev/null | tr -d '[:space:]'; }

echo "=================== scheduler: missed-run + multi-scheduler safety ==================="
docker compose up -d pg host1 host2 >/dev/null 2>&1
docker compose restart host1 host2 >/dev/null 2>&1; sleep 2
docker compose exec -T host1 $TB migrate:fresh --database=jobwarden >/dev/null 2>&1

# A schedule that fires every minute, "down" for 20 minutes → ~20 missed runs.
SCHED=$(docker compose exec -T host1 $TB jobwarden:demo:schedule --cron='* * * * *' --missed-minutes=20 --policy=run_all 2>&1 | grep -oE '[0-9a-f]{8}-[0-9a-f-]+' | head -1)
echo "  created schedule with a ~20-occurrence backlog: $SCHED"

echo "  racing FOUR scheduler evaluations concurrently (2× host1, 2× host2) ..."
docker compose exec -T host1 $TB jobwarden:schedule --once >/dev/null 2>&1 &
docker compose exec -T host1 $TB jobwarden:schedule --once >/dev/null 2>&1 &
docker compose exec -T host2 $TB jobwarden:schedule --once >/dev/null 2>&1 &
docker compose exec -T host2 $TB jobwarden:schedule --once >/dev/null 2>&1 &
wait

jobs=$(q "select count(*) from jobwarden_jobs where schedule_id='$SCHED'")
runs=$(q "select count(*) from jobwarden_schedule_runs where schedule_id='$SCHED' and action='enqueued'")
distinct_occ=$(q "select count(distinct occurrence_time) from jobwarden_schedule_runs where schedule_id='$SCHED'")
dupes=$(q "select count(*) from (select occurrence_time from jobwarden_schedule_runs where schedule_id='$SCHED' group by occurrence_time having count(*) > 1) d")
# A job linked from more than one occurrence would be a double-enqueue.
job_reused=$(q "select count(*) from (select job_id from jobwarden_schedule_runs where schedule_id='$SCHED' and job_id is not null group by job_id having count(*) > 1) x")

echo "  jobs=$jobs enqueued_runs=$runs distinct_occurrences=$distinct_occ duplicate_occurrences=$dupes jobs_linked_twice=$job_reused"
[ "${jobs:-0}" -gt 5 ]                && ok "missed runs were caught up ($jobs jobs)"                || bad "expected a backlog, got $jobs"
[ "$jobs" = "$runs" ]                 && ok "one job per enqueued occurrence"                        || bad "jobs=$jobs vs enqueued_runs=$runs"
[ "$dupes" = "0" ]                    && ok "NO occurrence materialized twice (UNIQUE held under the race)" || bad "$dupes duplicated occurrences"
[ "${job_reused:-1}" = "0" ]          && ok "no job was enqueued for two occurrences"               || bad "$job_reused jobs double-linked"

# Re-evaluating must enqueue nothing (already materialized).
before=$jobs
docker compose exec -T host1 $TB jobwarden:schedule --once >/dev/null 2>&1
[ "$(q "select count(*) from jobwarden_jobs where schedule_id='$SCHED'")" = "$before" ] && ok "re-evaluation enqueued nothing new (idempotent)" || bad "re-evaluation created duplicates"

# End-to-end: a supervisor runs the scheduled jobs.
echo "  running a supervisor to execute the scheduled jobs ..."
docker compose exec -d host1 $TB jobwarden:work --capacity=4 >/dev/null 2>&1
deadline=$((SECONDS+60))
while [ $SECONDS -lt $deadline ]; do
    [ "$(q "select count(*) from jobwarden_jobs where schedule_id='$SCHED' and state!='succeeded'")" = "0" ] && break
    sleep 2
done
[ "$(q "select count(*) from jobwarden_jobs where schedule_id='$SCHED' and state='succeeded'")" = "$jobs" ] && ok "all scheduled jobs ran to success" || bad "not all scheduled jobs succeeded"

echo
echo "=================== RESULT ==================="
echo "  PASS=$PASS  FAIL=$FAIL"
docker compose restart host1 host2 >/dev/null 2>&1
[ "$FAIL" = "0" ] && { echo "  ✅ SCHEDULER VERIFIED"; exit 0; } || { echo "  ❌ FAILURES"; exit 1; }
