#!/usr/bin/env bash
#
# Scheduled artisan command acceptance test — REAL processes (P14).
#
#   - the scheduler materializes a scheduled command onto the `scheduled` lane;
#   - a BUSINESS supervisor (default lane) must NOT touch it;
#   - the dedicated `jobwarden:scheduled-worker` runs it in a child process and
#     the command's output lands in the job log.
#
# Run from the repo root on the docker host:  bash tests/integration/scheduled_command.sh
set -uo pipefail

TB="vendor/bin/testbench"
PASS=0; FAIL=0
ok()  { echo "  ✅ $1"; PASS=$((PASS+1)); }
bad() { echo "  ❌ $1"; FAIL=$((FAIL+1)); }
q() { docker compose exec -T pg psql -U jobwarden -d jobwarden -tAc "$1" 2>/dev/null | tr -d '[:space:]'; }

echo "=================== scheduled artisan command (lanes + dedicated runner) ==================="
docker compose up -d pg host1 >/dev/null 2>&1
docker compose restart host1 >/dev/null 2>&1; sleep 2
docker compose exec -T host1 $TB migrate:fresh --database=jobwarden >/dev/null 2>&1

# Schedule the `demo:exit` artisan command, "down" 3 minutes so one run is due.
SCHED=$(docker compose exec -T host1 $TB jobwarden:demo:schedule --command=demo:exit --cron='* * * * *' --missed-minutes=3 --policy=run_latest 2>&1 | grep -oE '[0-9a-f]{8}-[0-9a-f-]+' | head -1)
echo "  scheduled command: $SCHED"

# Evaluate → materialize into the scheduled lane.
docker compose exec -T host1 $TB jobwarden:schedule --once >/dev/null 2>&1
JOB=$(q "select id from jobwarden_jobs where schedule_id='$SCHED' order by created_at desc limit 1")
lane=$(q "select lane from jobwarden_jobs where id='$JOB'")
class=$(q "select job_class from jobwarden_jobs where id='$JOB'")
echo "  materialized job=$JOB lane=$lane class=$class"
[ "$lane" = "scheduled" ] && ok "command materialized onto the 'scheduled' lane" || bad "lane=$lane"
echo "$class" | grep -q "RunArtisanCommand" && ok "job_class is RunArtisanCommand" || bad "class=$class"

# A BUSINESS supervisor (default lane) must NOT claim a scheduled-lane job.
docker compose exec -T host1 $TB jobwarden:work --lane=default --once >/dev/null 2>&1
docker compose exec -T host1 $TB jobwarden:work --lane=default --once >/dev/null 2>&1
st=$(q "select state from jobwarden_jobs where id='$JOB'")
echo "  after business (default-lane) ticks: job.state=$st"
[ "$st" = "queued" ] && ok "business fleet ignored the scheduled job (lane isolation)" || bad "business worker touched it (state=$st)"

# The dedicated runner picks it up and runs the command.
deadline=$((SECONDS+40))
while [ $SECONDS -lt $deadline ]; do
    [ "$(q "select state from jobwarden_jobs where id='$JOB'")" = "succeeded" ] && break
    docker compose exec -T host1 $TB jobwarden:scheduled-worker --once >/dev/null 2>&1
    sleep 2
done
final=$(q "select state from jobwarden_jobs where id='$JOB'")
output=$(q "select count(*) from jobwarden_job_logs where job_id='$JOB' and body_ref like '%hello-from-command%'")
echo "  final job.state=$final  command-output-lines-in-log=$output"
[ "$final" = "succeeded" ]  && ok "dedicated scheduled-worker ran the command to success" || bad "final state=$final"
[ "${output:-0}" -ge 1 ]   && ok "command output captured into the job log"               || bad "no command output in job_logs"

echo
echo "  PASS=$PASS  FAIL=$FAIL"
docker compose restart host1 >/dev/null 2>&1
[ "$FAIL" = "0" ] && { echo "  ✅ SCHEDULED COMMAND VERIFIED"; exit 0; } || { echo "  ❌ FAILURES"; exit 1; }
