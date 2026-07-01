#!/usr/bin/env bash
#
# Operator cancel/stop acceptance test — REAL, and it must CRUSH a busy process.
#
# An operator sets cancel desired-state on a RUNNING job. The job is "stubborn":
# it ignores SIGTERM (re-sleeps through it), modelling a wedged / CPU-bound
# worker. So the supervisor must escalate SIGTERM → (grace) → SIGKILL to actually
# kill it, then record the attempt `stopped` (term_signal=9) and the job `stopped`.
#
# Run from the repo root on the docker host:  bash tests/integration/cancel.sh
set -uo pipefail

TB="vendor/bin/testbench"
PASS=0; FAIL=0
ok()  { echo "  ✅ $1"; PASS=$((PASS+1)); }
bad() { echo "  ❌ $1"; FAIL=$((FAIL+1)); }

q() { docker compose exec -T pg psql -U jobwarden -d jobwarden -tAc "$1" 2>/dev/null | tr -d '[:space:]'; }
alive_in_host1() { docker compose exec -T host1 sh -c "test -d /proc/$1 && echo yes || echo no" 2>/dev/null | tr -d '[:space:]'; }

echo "=================== operator cancel crushes a busy (SIGTERM-ignoring) job ==================="
docker compose up -d pg host1 >/dev/null 2>&1
docker compose restart host1 >/dev/null 2>&1; sleep 2
docker compose exec -T host1 $TB migrate:fresh --database=jobwarden >/dev/null 2>&1

# A job that resists SIGTERM for 40s — only SIGKILL will stop it.
JOB=$(docker compose exec -T host1 $TB jobwarden:demo:dispatch stubborn --duration=40 2>&1 | grep -oE '[0-9a-f]{8}-[0-9a-f-]+')
echo "  dispatched stubborn job (40s, ignores SIGTERM): $JOB"

# graceful_timeout=2 → SIGTERM, then SIGKILL 2s later.
docker compose exec -d -e JOBWARDEN_GRACEFUL_TIMEOUT=2 host1 $TB jobwarden:work --capacity=2 >/dev/null 2>&1

deadline=$((SECONDS+25))
while [ $SECONDS -lt $deadline ]; do
    [ "$(q "select state from jobwarden_jobs where id='$JOB'")" = "running" ] && \
    [ -n "$(q "select child_pid from jobwarden_job_attempts where job_id='$JOB' order by attempt_number desc limit 1")" ] && break
    sleep 1
done
A1=$(q "select id from jobwarden_job_attempts where job_id='$JOB' order by attempt_number desc limit 1")
CHILD=$(q "select child_pid from jobwarden_job_attempts where id='$A1'")
echo "  running: attempt=$A1 child_pid=$CHILD"
[ "$(alive_in_host1 "$CHILD")" = "yes" ] && ok "the busy child is running" || bad "child not running"

echo "  >>> operator: jobwarden:cancel $JOB <<<"
docker compose exec -T host1 $TB jobwarden:cancel "$JOB" --reason="operator crush" 2>&1 | sed 's/^/    /'

# The supervisor observes the flag, SIGTERMs (ignored), then SIGKILLs after grace.
deadline=$((SECONDS+20))
while [ $SECONDS -lt $deadline ]; do
    st=$(q "select state from jobwarden_jobs where id='$JOB'")
    { [ "$st" = "stopped" ] || [ "$st" = "canceled" ]; } && break
    sleep 1
done

child_after=$(alive_in_host1 "$CHILD")
job_state=$(q "select state from jobwarden_jobs where id='$JOB'")
att_state=$(q "select state from jobwarden_job_attempts where id='$A1'")
term_sig=$(q "select term_signal from jobwarden_job_attempts where id='$A1'")
cancel_req=$(q "select cancel_requested from jobwarden_jobs where id='$JOB'")
echo "  after cancel: child=$child_after job.state=$job_state attempt.state=$att_state term_signal=$term_sig cancel_requested=$cancel_req"

[ "$child_after" = "no" ]     && ok "the busy process was CRUSHED (child gone)"            || bad "child still alive — cancel did not kill it"
[ "$att_state" = "stopped" ]  && ok "attempt recorded stopped"                             || bad "attempt state=$att_state"
[ "$term_sig" = "9" ]         && ok "term_signal=9 — it took SIGKILL to stop it"           || bad "term_signal=$term_sig (expected 9)"
[ "$job_state" = "stopped" ]  && ok "job recorded stopped (a running job halted)"          || bad "job state=$job_state"
[ "$cancel_req" = "t" ] || [ "$cancel_req" = "1" ] && ok "cancel desired-state was recorded" || bad "cancel_requested=$cancel_req"

echo
echo "=================== RESULT ==================="
echo "  PASS=$PASS  FAIL=$FAIL"
docker compose restart host1 >/dev/null 2>&1
[ "$FAIL" = "0" ] && { echo "  ✅ OPERATOR CANCEL VERIFIED"; exit 0; } || { echo "  ❌ FAILURES"; exit 1; }
