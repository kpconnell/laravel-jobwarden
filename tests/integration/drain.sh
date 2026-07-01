#!/usr/bin/env bash
#
# Graceful drain acceptance test — the ECS / container-stop contract.
#
# A running `jobwarden:work` is the container's root process. On SIGTERM it must:
#   1. stop claiming NEW jobs,
#   2. let in-flight children FINISH,
#   3. exit(0) once the final child is done.
# (ECS sends SIGTERM to PID 1, waits stopTimeout, then SIGKILLs — so a clean,
#  prompt exit(0) is what lets a deploy roll without losing or duplicating work.)
#
# Run from the repo root on the docker host:  bash tests/integration/drain.sh
set -uo pipefail

TB="vendor/bin/testbench"
PASS=0; FAIL=0
ok()  { echo "  ✅ $1"; PASS=$((PASS+1)); }
bad() { echo "  ❌ $1"; FAIL=$((FAIL+1)); }

q() { docker compose exec -T pg psql -U jobwarden -d jobwarden -tAc "$1" 2>/dev/null | tr -d '[:space:]'; }
alive_in_host1() { docker compose exec -T host1 sh -c "test -d /proc/$1 && echo yes || echo no" 2>/dev/null | tr -d '[:space:]'; }

echo "=================== graceful drain (SIGTERM → finish in-flight → exit 0) ==================="
docker compose up -d pg host1 >/dev/null 2>&1
docker compose restart host1 >/dev/null 2>&1; sleep 2
docker compose exec -T host1 $TB migrate:fresh --database=jobwarden >/dev/null 2>&1
docker compose exec -T host1 sh -c 'rm -f workbench/storage/jobwarden/sup.exit' >/dev/null 2>&1

# capacity=1: while the long job runs, the supervisor is full and CANNOT claim a
# second job — so when it drains, "second stays queued" proves it stopped
# claiming rather than just being busy.
LONG=$(docker compose exec -T host1 $TB jobwarden:demo:dispatch marker --sleep=6 2>&1 | grep -oE '[0-9a-f]{8}-[0-9a-f-]+')
echo "  dispatched long job (6s): $LONG"

# Run the supervisor as a root process that records its exit code.
docker compose exec -d host1 sh -c "$TB jobwarden:work --capacity=1; echo \$? > workbench/storage/jobwarden/sup.exit" >/dev/null 2>&1

# Wait until it is actually running the long job.
deadline=$((SECONDS+20))
while [ $SECONDS -lt $deadline ]; do
    [ "$(q "select state from jobwarden_jobs where id='$LONG'")" = "running" ] && break
    sleep 1
done
SUP_PID=$(q "select pid from jobwarden_workers where role='supervisor' order by started_at desc limit 1")
echo "  supervisor pid=$SUP_PID is running the long job"

# Now dispatch a SECOND job; the full (capacity=1) supervisor cannot take it yet.
SECOND=$(docker compose exec -T host1 $TB jobwarden:demo:dispatch marker --sleep=1 2>&1 | grep -oE '[0-9a-f]{8}-[0-9a-f-]+')
echo "  dispatched a second job: $SECOND (queued, supervisor is full)"
sleep 1

echo "  >>> SIGTERM the supervisor (like ECS stopping the task) <<<"
docker compose exec -T host1 kill -TERM "$SUP_PID" >/dev/null 2>&1
sleep 1

# It must still be alive — draining, not dead — while the long job finishes.
[ "$(alive_in_host1 "$SUP_PID")" = "yes" ] && ok "supervisor stayed alive to drain (did not die on SIGTERM)" || bad "supervisor exited before finishing in-flight work"

# Wait for it to drain + exit.
deadline=$((SECONDS+25))
while [ $SECONDS -lt $deadline ]; do
    [ "$(alive_in_host1 "$SUP_PID")" = "no" ] && break
    sleep 1
done

exit_code=$(docker compose exec -T host1 sh -c 'cat workbench/storage/jobwarden/sup.exit 2>/dev/null' | tr -d '[:space:]')
long_state=$(q "select state from jobwarden_jobs where id='$LONG'")
second_state=$(q "select state from jobwarden_jobs where id='$SECOND'")
second_attempts=$(q "select attempt_count from jobwarden_jobs where id='$SECOND'")
echo "  after drain: supervisor.exit=$exit_code long=$long_state second=$second_state second_attempts=$second_attempts"

[ "$(alive_in_host1 "$SUP_PID")" = "no" ] && ok "supervisor exited"                              || bad "supervisor never exited"
[ "$exit_code" = "0" ]          && ok "exit code is 0 (clean shutdown for the orchestrator)"      || bad "exit code is '$exit_code' (expected 0)"
[ "$long_state" = "succeeded" ] && ok "the in-flight job was allowed to FINISH"                   || bad "in-flight job ended '$long_state'"
[ "$second_state" = "queued" ]  && ok "a new job was NOT claimed while draining (left queued)"    || bad "second job is '$second_state' (drain claimed new work)"
[ "${second_attempts:-x}" = "0" ] && ok "the queued job has 0 attempts (untouched)"              || bad "second job has $second_attempts attempts"

echo
echo "  (a now-fresh supervisor should still pick up the leftover job:)"
docker compose exec -d host1 $TB jobwarden:work --capacity=2 >/dev/null 2>&1
deadline=$((SECONDS+25))
while [ $SECONDS -lt $deadline ]; do
    [ "$(q "select state from jobwarden_jobs where id='$SECOND'")" = "succeeded" ] && break
    sleep 1
done
[ "$(q "select state from jobwarden_jobs where id='$SECOND'")" = "succeeded" ] && ok "leftover job ran on the next supervisor" || bad "leftover job not picked up"

# ---------------------------------- PART 2: bounded drain timeout -------------
echo
echo "=================== drain timeout (bounded wait; child finishes on its own) ==================="
docker compose restart host1 >/dev/null 2>&1; sleep 2
docker compose exec -T host1 $TB migrate:fresh --database=jobwarden >/dev/null 2>&1
docker compose exec -T host1 sh -c 'rm -f workbench/storage/jobwarden/sup.exit' >/dev/null 2>&1

LONG2=$(docker compose exec -T host1 $TB jobwarden:demo:dispatch marker --sleep=15 2>&1 | grep -oE '[0-9a-f]{8}-[0-9a-f-]+')
echo "  dispatched a 15s job: $LONG2 (supervisor will be told to wait at most 3s)"
docker compose exec -d host1 sh -c "$TB jobwarden:work --capacity=1 --drain-timeout=3; echo \$? > workbench/storage/jobwarden/sup.exit" >/dev/null 2>&1

deadline=$((SECONDS+20))
while [ $SECONDS -lt $deadline ]; do
    [ "$(q "select state from jobwarden_jobs where id='$LONG2'")" = "running" ] && break
    sleep 1
done
SUP2=$(q "select pid from jobwarden_workers where role='supervisor' order by started_at desc limit 1")
echo "  supervisor pid=$SUP2 running the 15s job; SIGTERM with --drain-timeout=3"

t0=$SECONDS
docker compose exec -T host1 kill -TERM "$SUP2" >/dev/null 2>&1
deadline=$((SECONDS+20))
while [ $SECONDS -lt $deadline ]; do
    [ "$(alive_in_host1 "$SUP2")" = "no" ] && break
    sleep 1
done
drain_secs=$((SECONDS - t0))
exit2=$(docker compose exec -T host1 sh -c 'cat workbench/storage/jobwarden/sup.exit 2>/dev/null' | tr -d '[:space:]')
echo "  supervisor exited ~${drain_secs}s after SIGTERM (timeout=3, job needs 15), exit=$exit2"
[ "${drain_secs:-99}" -le 8 ] && ok "supervisor stopped at the drain timeout (did NOT block for the 15s job)" || bad "waited ${drain_secs}s (drain timeout not honored)"
[ "$exit2" = "0" ]            && ok "exit code 0"                                                            || bad "exit code '$exit2'"

echo "  the abandoned child keeps running and self-reports its own outcome:"
deadline=$((SECONDS+25))
while [ $SECONDS -lt $deadline ]; do
    [ "$(q "select state from jobwarden_jobs where id='$LONG2'")" = "succeeded" ] && break
    sleep 2
done
[ "$(q "select state from jobwarden_jobs where id='$LONG2'")" = "succeeded" ] && ok "child completed independently and self-reported succeeded" || bad "abandoned child did not complete"

echo
echo "=================== RESULT ==================="
echo "  PASS=$PASS  FAIL=$FAIL"
docker compose restart host1 >/dev/null 2>&1
[ "$FAIL" = "0" ] && { echo "  ✅ GRACEFUL DRAIN VERIFIED"; exit 0; } || { echo "  ❌ FAILURES"; exit 1; }
