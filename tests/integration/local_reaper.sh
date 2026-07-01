#!/usr/bin/env bash
#
# Tier-2 local reaper acceptance test — REAL, single host, no mocks.
#
# Scenario the GLOBAL reaper cannot handle: the SUPERVISOR dies but the HOST
# lives. Its child (jobwarden:run) reparents to init and keeps running. The
# per-host local reaper must find that reparented child by its /proc stamp,
# SIGTERM→SIGKILL it, confirm it's dead, and only THEN orphan the attempt — then
# an idempotent recovery re-runs the job.
#
# Run from the repo root on the docker host:  bash tests/integration/local_reaper.sh
set -uo pipefail

TB="vendor/bin/testbench"
PASS=0; FAIL=0
ok()  { echo "  ✅ $1"; PASS=$((PASS+1)); }
bad() { echo "  ❌ $1"; FAIL=$((FAIL+1)); }

q() { docker compose exec -T pg psql -U jobwarden -d jobwarden -tAc "$1" 2>/dev/null | tr -d '[:space:]'; }
alive_in_host1() { docker compose exec -T host1 sh -c "test -d /proc/$1 && echo yes || echo no" 2>/dev/null | tr -d '[:space:]'; }
ppid_in_host1()  { docker compose exec -T host1 sh -c "awk '{print \$4}' /proc/$1/stat 2>/dev/null" | tr -d '[:space:]'; }

echo "=================== Tier-2 local reaper (real, host1) ==================="
docker compose up -d pg host1 globalreaper >/dev/null 2>&1
docker compose restart host1 >/dev/null 2>&1; sleep 2
docker compose exec -T host1 $TB migrate:fresh --database=jobwarden >/dev/null 2>&1

# A stubborn, idempotent job that resists SIGTERM (only SIGKILL stops it).
JOB=$(docker compose exec -T host1 $TB jobwarden:demo:dispatch stubborn --duration=25 --max-attempts=3 2>&1 | grep -oE '[0-9a-f]{8}-[0-9a-f-]+')
echo "  dispatched stubborn idempotent job: $JOB"

docker compose exec -d host1 $TB jobwarden:work --capacity=2 >/dev/null 2>&1

# Wait until host1 is actually running it (child spawned + stamped).
deadline=$((SECONDS+30))
while [ $SECONDS -lt $deadline ]; do
    [ "$(q "select state from jobwarden_jobs where id='$JOB'")" = "running" ] && \
    [ -n "$(q "select child_pid from jobwarden_job_attempts where job_id='$JOB' order by attempt_number desc limit 1")" ] && break
    sleep 1
done

A1=$(q  "select id from jobwarden_job_attempts where job_id='$JOB' order by attempt_number desc limit 1")
SUP=$(q "select supervisor_pid from jobwarden_job_attempts where id='$A1'")
CHILD=$(q "select child_pid from jobwarden_job_attempts where id='$A1'")
echo "  running: attempt=$A1 supervisor_pid=$SUP child_pid=$CHILD"
[ "$(alive_in_host1 "$CHILD")" = "yes" ] && ok "child $CHILD is running on host1" || bad "child not running"

echo "  >>> kill ONLY the supervisor (host stays alive) <<<"
docker compose exec -T host1 pkill -9 -f 'jobwarden:work' >/dev/null 2>&1
sleep 2

sup_alive=$(alive_in_host1 "$SUP")
child_alive=$(alive_in_host1 "$CHILD")
child_ppid=$(ppid_in_host1 "$CHILD")
echo "  after supervisor kill: supervisor=$sup_alive child=$child_alive child_ppid=$child_ppid"
[ "$sup_alive" = "no" ]    && ok "supervisor is dead"                            || bad "supervisor still alive"
[ "$child_alive" = "yes" ] && ok "child SURVIVED and kept running (reparented)"  || bad "child died with the supervisor"
[ "$child_ppid" = "1" ]    && ok "child reparented to init (ppid=1)"             || bad "child ppid=$child_ppid (expected 1)"

echo "  running the local reaper on host1 ..."
for i in 1 2 3; do
    docker compose exec -T -e JOBWARDEN_GRACEFUL_TIMEOUT=2 -e JOBWARDEN_BACKOFF_STRATEGY=fixed -e JOBWARDEN_BACKOFF_BASE=1 \
        host1 $TB jobwarden:reap:local --once 2>&1 | sed 's/^/    reaper: /'
    [ "$(q "select state from jobwarden_job_attempts where id='$A1'")" = "orphaned" ] && break
    sleep 1
done

child_after=$(alive_in_host1 "$CHILD")
att_state=$(q "select state from jobwarden_job_attempts where id='$A1'")
fence=$(q "select fencing_token from jobwarden_job_attempts where id='$A1'")
job_state=$(q "select state from jobwarden_jobs where id='$JOB'")
reaplog=$(q "select count(*) from jobwarden_job_logs where attempt_id='$A1' and step='reaped'")
echo "  after reap: child=$child_after attempt.state=$att_state fence=$fence job.state=$job_state reaplog=$reaplog"
[ "$child_after" = "no" ]      && ok "reaper SIGKILLed the reparented child"            || bad "child still alive after reap"
[ "$att_state" = "orphaned" ]  && ok "attempt orphaned (only after child confirmed dead)" || bad "attempt state=$att_state"
[ "${fence:-0}" -ge 2 ]        && ok "fencing token bumped ($fence)"                    || bad "fence not bumped ($fence)"
[ "${reaplog:-0}" -ge 1 ]      && ok "reaper recorded its action in the job log"        || bad "no reaper entry in job_logs"
case "$job_state" in retrying|queued|running|succeeded) ok "idempotent job recovered (state=$job_state)";; *) bad "job did not recover (state=$job_state)";; esac

echo "  bringing a fresh supervisor up to absorb the recovered job ..."
docker compose exec -d host1 $TB jobwarden:work --capacity=2 >/dev/null 2>&1
deadline=$((SECONDS+50))
while [ $SECONDS -lt $deadline ]; do
    st=$(q "select state from jobwarden_jobs where id='$JOB'")
    { [ "$st" = "succeeded" ] || [ "$st" = "failed" ]; } && break
    sleep 2
done
final=$(q "select state from jobwarden_jobs where id='$JOB'")
attempts=$(q "select count(*) from jobwarden_job_attempts where job_id='$JOB'")
echo "  final: job.state=$final attempts=$attempts"
[ "$final" = "succeeded" ] && ok "job ultimately succeeded after supervisor loss" || bad "job final state=$final"
[ "${attempts:-0}" -ge 2 ] && ok "a fresh attempt was minted (attempts=$attempts)" || bad "no new attempt"

echo
echo "=================== RESULT ==================="
echo "  PASS=$PASS  FAIL=$FAIL"
docker compose restart host1 >/dev/null 2>&1
[ "$FAIL" = "0" ] && { echo "  ✅ TIER-2 LOCAL REAPER VERIFIED"; exit 0; } || { echo "  ❌ FAILURES"; exit 1; }
