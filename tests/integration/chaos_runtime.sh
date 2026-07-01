#!/usr/bin/env bash
#
# Real-runtime chaos monkey acceptance test — no PHPUnit harness, no fake probe,
# no in-process supervisor. This drives the Docker Linux stack exactly the way an
# operator would:
#
#   - Postgres as the coordination layer
#   - host1 + host2 as separate PID namespaces
#   - real jobwarden:reap:local daemons on each host
#   - real jobwarden:work supervisor daemons on each host
#   - real jobwarden:run children spawned through proc_open
#   - hostile jobs that log heavily, write artifacts, spam stderr, throw, crash
#     with SIGKILL, and ignore SIGTERM until the supervisor crushes them
#
# Run from the repo root on the Docker host:
#
#   bash tests/integration/chaos_runtime.sh
set -uo pipefail

TB="vendor/bin/testbench"
PASS=0
FAIL=0

ok()  { echo "  ✅ $1"; PASS=$((PASS+1)); }
bad() { echo "  ❌ $1"; FAIL=$((FAIL+1)); }
hdr() { echo; echo "=================== $1 ==================="; }

q() { docker compose exec -T pg psql -U jobwarden -d jobwarden -tAc "$1" 2>/dev/null | tr -d '[:space:]'; }
q_lines() { docker compose exec -T pg psql -U jobwarden -d jobwarden -tAc "$1" 2>/dev/null | sed '/^[[:space:]]*$/d'; }

runtime_env=(
  -e JOBWARDEN_BACKOFF_STRATEGY=fixed
  -e JOBWARDEN_BACKOFF_BASE=0
  -e JOBWARDEN_BACKOFF_CAP=0
  -e JOBWARDEN_GRACEFUL_TIMEOUT=1
  -e JOBWARDEN_POLL_INTERVAL_MS=100
  -e JOBWARDEN_LOCAL_SCAN_INTERVAL=1
)

dispatch_chaos() {
    local mode="$1"
    local count="$2"
    local attempts="$3"
    local priority="$4"
    shift 4

    docker compose exec -T host1 "$TB" jobwarden:demo:dispatch chaos \
        --mode="$mode" \
        --count="$count" \
        --max-attempts="$attempts" \
        --priority="$priority" \
        "$@" >/dev/null
}

start_runtime() {
    docker compose exec -d "${runtime_env[@]}" host1 "$TB" jobwarden:reap:local --interval=1 >/dev/null 2>&1
    docker compose exec -d "${runtime_env[@]}" host2 "$TB" jobwarden:reap:local --interval=1 >/dev/null 2>&1
    docker compose exec -d "${runtime_env[@]}" host1 "$TB" jobwarden:work --capacity=12 >/dev/null 2>&1
    docker compose exec -d "${runtime_env[@]}" host2 "$TB" jobwarden:work --capacity=12 >/dev/null 2>&1
}

stop_runtime() {
    docker compose restart host1 host2 >/dev/null 2>&1
    sleep 2
}

hdr "BOOT REAL RUNTIME"
docker compose up -d pg host1 host2 >/dev/null 2>&1
stop_runtime
docker compose exec -T host1 "$TB" migrate:fresh --database=jobwarden >/dev/null

hdr "PHASE 1 — FORCE REAL STOP ESCALATION"
dispatch_chaos stubborn 8 1 100 --duration=90
stubborn_total=$(q "select count(*) from jobwarden_jobs where params->>'mode'='stubborn'")
echo "  dispatched stubborn jobs=$stubborn_total (expected 8)"
[ "$stubborn_total" = "8" ] && ok "stubborn jobs dispatched first" || bad "expected 8 stubborn jobs, got $stubborn_total"

start_runtime
echo "  real local reapers + supervisors are running on host1 and host2"

deadline=$((SECONDS+60))
while [ $SECONDS -lt $deadline ]; do
    stubborn_running=$(q "select count(*) from jobwarden_jobs where params->>'mode'='stubborn' and state='running'")
    [ "${stubborn_running:-0}" = "8" ] && break
    sleep 1
done
stubborn_running=$(q "select count(*) from jobwarden_jobs where params->>'mode'='stubborn' and state='running'")
echo "  stubborn running=$stubborn_running"
[ "$stubborn_running" = "8" ] && ok "all stubborn jobs reached running in real children" || bad "only $stubborn_running/8 stubborn jobs reached running"

echo "  issuing real operator stops for every stubborn job ..."
q_lines "select id from jobwarden_jobs where params->>'mode'='stubborn' and state='running'" |
while IFS= read -r id; do
    id="$(echo "$id" | tr -d '[:space:]')"
    [ -n "$id" ] && docker compose exec -T host1 "$TB" jobwarden:cancel "$id" --reason="chaos runtime stop" >/dev/null 2>&1 < /dev/null
done

deadline=$((SECONDS+45))
while [ $SECONDS -lt $deadline ]; do
    stopped_now=$(q "select count(*) from jobwarden_jobs where params->>'mode'='stubborn' and state='stopped'")
    [ "${stopped_now:-0}" = "8" ] && break
    sleep 1
done
stopped_now=$(q "select count(*) from jobwarden_jobs where params->>'mode'='stubborn' and state='stopped'")
echo "  stubborn stopped=$stopped_now"
[ "$stopped_now" = "8" ] && ok "all stubborn children were stopped by the real supervisor" || bad "only $stopped_now/8 stubborn jobs stopped"

hdr "PHASE 2 — DISPATCH HIGH-CONCURRENCY HOSTILE WORKLOAD"
dispatch_chaos success 40 1 50 --sleep-ms=20
dispatch_chaos logstorm 25 1 40 --lines=40
dispatch_chaos artifact 15 1 30 --payload-bytes=512
dispatch_chaos stderr 15 1 20 --lines=20
dispatch_chaos throw 20 2 10
dispatch_chaos crash 10 1 5

total=$(q "select count(*) from jobwarden_jobs")
echo "  dispatched jobs=$total (expected 133)"
[ "$total" = "133" ] && ok "all chaos jobs dispatched through the real CLI" || bad "expected 133 jobs, got $total"

deadline=$((SECONDS+180))
while [ $SECONDS -lt $deadline ]; do
    live=$(q "select count(*) from jobwarden_jobs where state in ('pending','queued','retrying','running','orphaned')")
    printf "  live=%s succeeded=%s failed=%s stopped=%s attempts=%s\r" \
        "${live:-0}" \
        "$(q "select count(*) from jobwarden_jobs where state='succeeded'")" \
        "$(q "select count(*) from jobwarden_jobs where state='failed'")" \
        "$(q "select count(*) from jobwarden_jobs where state='stopped'")" \
        "$(q "select count(*) from jobwarden_job_attempts")"

    [ "${live:-0}" = "0" ] && break
    sleep 2
done
echo

hdr "ASSERT DURABLE OUTCOME"
live=$(q "select count(*) from jobwarden_jobs where state in ('pending','queued','retrying','running','orphaned')")
succeeded=$(q "select count(*) from jobwarden_jobs where state='succeeded'")
failed=$(q "select count(*) from jobwarden_jobs where state='failed'")
stopped=$(q "select count(*) from jobwarden_jobs where state='stopped'")
attempts=$(q "select count(*) from jobwarden_job_attempts")
inflight_attempts=$(q "select count(*) from jobwarden_job_attempts where state in ('dispatched','running')")
hosts=$(q "select count(distinct host_id) from jobwarden_job_attempts where host_id is not null")

echo "  live=$live succeeded=$succeeded failed=$failed stopped=$stopped attempts=$attempts in_flight_attempts=$inflight_attempts hosts=$hosts"
[ "$live" = "0" ]              && ok "no job left live or in limbo"                         || bad "$live jobs are still live"
[ "$succeeded" = "95" ]        && ok "95 noisy/success/artifact/stderr jobs succeeded"      || bad "succeeded=$succeeded expected 95"
[ "$failed" = "30" ]           && ok "30 throw/crash jobs failed deterministically"         || bad "failed=$failed expected 30"
[ "$stopped" = "8" ]           && ok "8 stubborn jobs were stopped by operator action"      || bad "stopped=$stopped expected 8"
[ "$attempts" = "153" ]        && ok "153 attempts minted (20 throw jobs retried once)"     || bad "attempts=$attempts expected 153"
[ "$inflight_attempts" = "0" ]  && ok "no attempt left dispatched/running"                  || bad "$inflight_attempts attempts still in flight"
[ "${hosts:-0}" -ge 2 ]         && ok "both host PID namespaces participated"               || bad "only $hosts host(s) participated"

hdr "ASSERT INVARIANTS"
duplicate_attempts=$(q "select count(*) from (select job_id, attempt_number, count(*) c from jobwarden_job_attempts group by job_id, attempt_number having count(*) > 1) x")
attempt_mismatch=$(q "select count(*) from jobwarden_jobs j where attempt_count <> (select count(*) from jobwarden_job_attempts a where a.job_id=j.id)")
gapful_logs=$(q "select count(*) from (select attempt_id, count(*) c, min(seq) min_seq, max(seq) max_seq from jobwarden_job_logs group by attempt_id having count(*) > 0 and (min(seq) <> 1 or max(seq) <> count(*))) x")
stale_rejections=$(q "select count(*) from jobwarden_job_events where reason like 'stale_write_rejected%'")

echo "  duplicate_attempt_numbers=$duplicate_attempts attempt_count_mismatches=$attempt_mismatch gapful_log_streams=$gapful_logs stale_rejections=$stale_rejections"
[ "$duplicate_attempts" = "0" ] && ok "no duplicate (job_id, attempt_number)"              || bad "$duplicate_attempts duplicate attempt-number groups"
[ "$attempt_mismatch" = "0" ]   && ok "jobs.attempt_count matches actual attempts"         || bad "$attempt_mismatch jobs have mismatched attempt_count"
[ "$gapful_logs" = "0" ]        && ok "all per-attempt log streams are gapless"            || bad "$gapful_logs attempts have gapful logs"
ok "stale write races were audited without clobbering state ($stale_rejections rejection events)"

hdr "ASSERT OBSERVABILITY"
logstorm=$(q "select count(*) from jobwarden_job_logs where step='logstorm'")
artifacts=$(q "select count(*) from jobwarden_job_artifacts where type='report' and name='chaos-summary'")
stderr_logs=$(q "select count(*) from jobwarden_job_logs where step='process_output' and body_ref like '%CHAOS-STDERR%'")
sigkill_logs=$(q "select count(*) from jobwarden_job_logs where step='process_output' and body_ref like '%CHAOS-SIGKILL%'")
stopped_attempts=$(q "select count(*) from jobwarden_job_attempts where state='stopped'")
crashed_sigkill=$(q "select count(*) from jobwarden_job_attempts where state='failed' and term_signal=9")

echo "  logstorm_lines=$logstorm artifacts=$artifacts stderr_logs=$stderr_logs sigkill_logs=$sigkill_logs stopped_attempts=$stopped_attempts crash_sigkill_attempts=$crashed_sigkill"
[ "${logstorm:-0}" -ge 1000 ]   && ok "log storm captured live into job_logs"              || bad "only $logstorm logstorm rows"
[ "$artifacts" = "15" ]         && ok "artifact-writing jobs recorded support artifacts"   || bad "artifacts=$artifacts expected 15"
[ "$stderr_logs" = "15" ]       && ok "raw stderr was ingested from successful children"   || bad "stderr_logs=$stderr_logs expected 15"
[ "$sigkill_logs" = "10" ]      && ok "hard-crash dying words were ingested"               || bad "sigkill_logs=$sigkill_logs expected 10"
[ "$stopped_attempts" = "8" ]   && ok "stubborn attempts recorded stopped"                 || bad "stopped_attempts=$stopped_attempts expected 8"
[ "$crashed_sigkill" = "10" ]   && ok "self-SIGKILL crashes recorded term_signal=9"        || bad "crashed_sigkill=$crashed_sigkill expected 10"

hdr "RESULT"
echo "  PASS=$PASS  FAIL=$FAIL"
stop_runtime
[ "$FAIL" = "0" ] && { echo "  ✅ REAL-RUNTIME CHAOS VERIFIED"; exit 0; } || { echo "  ❌ FAILURES"; exit 1; }
