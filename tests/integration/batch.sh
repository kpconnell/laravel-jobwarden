#!/usr/bin/env bash
#
# Batches & dependencies acceptance test — REAL supervisors, no mocks (spec §8).
#
#   fan-out  : N independent members across host1+host2 → batch succeeded.
#   chain    : a→b→c — a dependent is NEVER claimed before its predecessor
#              succeeds (the admit gate), and the batch succeeds in order.
#   fail_fast: a member fails → remaining members are canceled, batch failed.
#
# Run from the repo root on the docker host:  bash tests/integration/batch.sh
set -uo pipefail

TB="vendor/bin/testbench"
PASS=0; FAIL=0
ok()  { echo "  ✅ $1"; PASS=$((PASS+1)); }
bad() { echo "  ❌ $1"; FAIL=$((FAIL+1)); }

q() { docker compose exec -T pg psql -U jobwarden -d jobwarden -tAc "$1" 2>/dev/null | tr -d '[:space:]'; }

reset_with_supervisors() {
    docker compose restart host1 host2 >/dev/null 2>&1; sleep 2
    docker compose exec -T host1 $TB migrate:fresh --database=jobwarden >/dev/null 2>&1
}

# ----------------------------------------------------- fan-out ----------------
echo "=================== fan-out batch across host1 + host2 ==================="
docker compose up -d pg host1 host2 >/dev/null 2>&1
reset_with_supervisors
BATCH=$(docker compose exec -T host1 $TB jobwarden:demo:batch fanout --size=8 --sleep=1 2>&1 | grep -oE '[0-9a-f]{8}-[0-9a-f-]+' | head -1)
echo "  dispatched fan-out batch (8 members): $BATCH"
docker compose exec -d host1 $TB jobwarden:work --capacity=3 >/dev/null 2>&1
docker compose exec -d host2 $TB jobwarden:work --capacity=3 >/dev/null 2>&1

deadline=$((SECONDS+60))
while [ $SECONDS -lt $deadline ]; do
    [ "$(q "select state from jobwarden_batches where id='$BATCH'")" = "succeeded" ] && break
    sleep 2
done
state=$(q "select state from jobwarden_batches where id='$BATCH'")
succ=$(q "select succeeded_count from jobwarden_batches where id='$BATCH'")
hosts=$(q "select count(distinct host_id) from jobwarden_job_attempts a join jobwarden_jobs j on j.id=a.job_id where j.batch_id='$BATCH'")
echo "  batch.state=$state succeeded=$succ distinct_hosts=$hosts"
[ "$state" = "succeeded" ] && ok "fan-out batch succeeded" || bad "batch state=$state"
[ "$succ" = "8" ]          && ok "all 8 members succeeded"  || bad "succeeded=$succ"
[ "${hosts:-0}" -ge 2 ]    && ok "members ran across BOTH hosts" || bad "only $hosts host(s)"

# ----------------------------------------------------- chain ------------------
echo
echo "=================== chain a→b→c (dependency admit gate) ==================="
reset_with_supervisors
CHAIN=$(docker compose exec -T host1 $TB jobwarden:demo:batch chain --sleep=2 2>&1 | grep -oE '[0-9a-f]{8}-[0-9a-f-]+' | head -1)
echo "  dispatched chain batch: $CHAIN"
# Exactly one member eligible at the start; the other two gated.
queued0=$(q "select count(*) from jobwarden_jobs where batch_id='$CHAIN' and state='queued'")
pending0=$(q "select count(*) from jobwarden_jobs where batch_id='$CHAIN' and state='pending'")
echo "  at dispatch: queued=$queued0 pending=$pending0"
[ "$queued0" = "1" ] && [ "$pending0" = "2" ] && ok "only the root is eligible; dependents gated" || bad "queued=$queued0 pending=$pending0"

docker compose exec -d host1 $TB jobwarden:work --capacity=3 >/dev/null 2>&1
# Invariant we check throughout: at most ONE member running at a time (strict chain).
max_concurrent=0
deadline=$((SECONDS+60))
while [ $SECONDS -lt $deadline ]; do
    running=$(q "select count(*) from jobwarden_jobs where batch_id='$CHAIN' and state='running'")
    [ "${running:-0}" -gt "$max_concurrent" ] && max_concurrent=$running
    [ "$(q "select state from jobwarden_batches where id='$CHAIN'")" = "succeeded" ] && break
    sleep 1
done
cstate=$(q "select state from jobwarden_batches where id='$CHAIN'")
csucc=$(q "select succeeded_count from jobwarden_batches where id='$CHAIN'")
echo "  chain batch.state=$cstate succeeded=$csucc max_concurrent_observed=$max_concurrent"
[ "$cstate" = "succeeded" ]   && ok "chain batch succeeded"                          || bad "chain state=$cstate"
[ "$csucc" = "3" ]            && ok "all 3 chain members succeeded"                  || bad "succeeded=$csucc"
[ "${max_concurrent:-9}" -le 1 ] && ok "never more than one member ran at once (deps respected)" || bad "saw $max_concurrent concurrent (a dependent ran early!)"

# ----------------------------------------------------- fail_fast --------------
echo
echo "=================== fail_fast (one member fails → rest canceled) ==================="
reset_with_supervisors
FF=$(docker compose exec -T host1 $TB jobwarden:demo:batch failfast 2>&1 | grep -oE '[0-9a-f]{8}-[0-9a-f-]+' | head -1)
echo "  dispatched fail_fast batch (1 failing + 4 slow members): $FF"
docker compose exec -d host1 $TB jobwarden:work --capacity=3 >/dev/null 2>&1

deadline=$((SECONDS+40))
while [ $SECONDS -lt $deadline ]; do
    st=$(q "select state from jobwarden_batches where id='$FF'")
    case "$st" in failed|succeeded|partial|canceled) break;; esac
    sleep 1
done
ffstate=$(q "select state from jobwarden_batches where id='$FF'")
fffailed=$(q "select failed_count from jobwarden_batches where id='$FF'")
ffcanceled=$(q "select count(*) from jobwarden_jobs where batch_id='$FF' and state in ('canceled','stopped')")
echo "  fail_fast batch.state=$ffstate failed=$fffailed canceled_members=$ffcanceled"
[ "$ffstate" = "failed" ]  && ok "batch failed on first member failure"        || bad "batch state=$ffstate"
[ "${fffailed:-0}" -ge 1 ] && ok "the failing member is recorded"              || bad "failed_count=$fffailed"
[ "${ffcanceled:-0}" -ge 1 ] && ok "remaining members were canceled (fail_fast)" || bad "canceled_members=$ffcanceled"

echo
echo "=================== RESULT ==================="
echo "  PASS=$PASS  FAIL=$FAIL"
docker compose restart host1 host2 >/dev/null 2>&1
[ "$FAIL" = "0" ] && { echo "  ✅ BATCHES & DEPENDENCIES VERIFIED"; exit 0; } || { echo "  ❌ FAILURES"; exit 1; }
