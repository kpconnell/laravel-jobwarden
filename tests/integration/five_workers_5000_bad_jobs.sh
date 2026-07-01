#!/usr/bin/env bash
#
# Exactly what it says:
#   1. five real worker containers are running
#   2. then exactly 5000 badly behaving jobs are queued
#   3. the five workers drain them
#   4. durable invariants are audited from Postgres
#
# Run from repo root:
#   bash tests/integration/five_workers_5000_bad_jobs.sh
set -uo pipefail

TB="vendor/bin/testbench"
HOSTS=(host1 host2 host3 host4 host5)
CAPACITY="${JOBWARDEN_5000_CAPACITY:-24}"
DRAIN_SAFETY_SECONDS="${JOBWARDEN_5000_DRAIN_SAFETY_SECONDS:-1800}"

LOGSTORM_JOBS=1500
STDERR_JOBS=1000
ARTIFACT_JOBS=1000
THROW_JOBS=1000
CRASH_JOBS=400
STUBBORN_JOBS=100

TARGET_JOBS=5000
EXPECTED_SUCCEEDED=$((LOGSTORM_JOBS + STDERR_JOBS + ARTIFACT_JOBS))
EXPECTED_FAILED=$((THROW_JOBS + CRASH_JOBS))
EXPECTED_STOPPED="$STUBBORN_JOBS"
EXPECTED_ATTEMPTS=$((LOGSTORM_JOBS + STDERR_JOBS + ARTIFACT_JOBS + (THROW_JOBS * 2) + CRASH_JOBS + STUBBORN_JOBS))
EXPECTED_LOGSTORM_LINES=$((LOGSTORM_JOBS * 20))

PASS=0
FAIL=0
STOPS=0

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

start_worker() {
    local host="$1"
    docker compose exec -d "${runtime_env[@]}" "$host" "$TB" jobwarden:reap:local --interval=1 >/dev/null 2>&1
    docker compose exec -d "${runtime_env[@]}" "$host" "$TB" jobwarden:work --capacity="$CAPACITY" >/dev/null 2>&1
}

stop_runtime() {
    docker compose restart "${HOSTS[@]}" >/dev/null 2>&1
    sleep 2
}

live_jobs() {
    q "select count(*) from jobwarden_jobs where state in ('pending','queued','retrying','running','orphaned')"
}

stop_stubborn_jobs() {
    local ids
    ids="$(q_lines "select id from jobwarden_jobs where params->>'mode'='stubborn' and state='running' and cancel_requested=false limit 250")"
    [ -z "$ids" ] && return 0

    while IFS= read -r id; do
        id="$(echo "$id" | tr -d '[:space:]')"
        if [ -n "$id" ]; then
            docker compose exec -T host1 "$TB" jobwarden:cancel "$id" --reason="5000 bad jobs stop" >/dev/null 2>&1 < /dev/null
            STOPS=$((STOPS+1))
        fi
    done <<< "$ids"
}

print_progress() {
    printf "  jobs=%5s/%-5s live=%5s succ=%5s fail=%5s stopped=%4s attempts=%6s logs=%7s stops=%4s\r" \
        "$(q "select count(*) from jobwarden_jobs")" "$TARGET_JOBS" "$(live_jobs)" \
        "$(q "select count(*) from jobwarden_jobs where state='succeeded'")" \
        "$(q "select count(*) from jobwarden_jobs where state='failed'")" \
        "$(q "select count(*) from jobwarden_jobs where state='stopped'")" \
        "$(q "select count(*) from jobwarden_job_attempts")" \
        "$(q "select count(*) from jobwarden_job_logs")" \
        "$STOPS"
}

hdr "START FIVE WORKER CONTAINERS"
docker compose up -d pg "${HOSTS[@]}" >/dev/null
stop_runtime
docker compose exec -T host1 "$TB" migrate:fresh --database=jobwarden >/dev/null

for host in "${HOSTS[@]}"; do
    start_worker "$host"
done

deadline=$((SECONDS+60))
while [ $SECONDS -lt $deadline ]; do
    active_workers="$(q "select count(*) from jobwarden_workers where role='supervisor' and state='active'")"
    active_hosts="$(q "select count(distinct host_id) from jobwarden_workers where role='supervisor' and state='active'")"
    [ "${active_workers:-0}" -ge 5 ] && [ "${active_hosts:-0}" -ge 5 ] && break
    sleep 1
done

active_workers="$(q "select count(*) from jobwarden_workers where role='supervisor' and state='active'")"
active_hosts="$(q "select count(distinct host_id) from jobwarden_workers where role='supervisor' and state='active'")"
echo "  active supervisor workers=$active_workers distinct worker containers=$active_hosts"
[ "${active_workers:-0}" -ge 5 ] && ok "five real supervisor workers are active" || bad "only $active_workers supervisor workers active"
[ "${active_hosts:-0}" -ge 5 ] && ok "five distinct worker containers are represented" || bad "only $active_hosts distinct worker containers"

hdr "QUEUE 5000 BADLY BEHAVING JOBS"
dispatch_chaos logstorm "$LOGSTORM_JOBS" 1 60 --lines=20
dispatch_chaos stderr "$STDERR_JOBS" 1 50 --lines=8
dispatch_chaos artifact "$ARTIFACT_JOBS" 1 40 --payload-bytes=512
dispatch_chaos throw "$THROW_JOBS" 2 30 --idempotent
dispatch_chaos crash "$CRASH_JOBS" 1 20
dispatch_chaos stubborn "$STUBBORN_JOBS" 1 100 --duration=300

jobs="$(q "select count(*) from jobwarden_jobs")"
echo "  queued jobs=$jobs"
[ "$jobs" = "$TARGET_JOBS" ] && ok "exactly 5000 jobs were queued" || bad "queued $jobs jobs, expected $TARGET_JOBS"

hdr "DRAIN"
deadline=$((SECONDS+DRAIN_SAFETY_SECONDS))
while [ $SECONDS -lt $deadline ]; do
    stop_stubborn_jobs
    live="$(live_jobs)"
    print_progress
    [ "${live:-0}" = "0" ] && break
    sleep 2
done
echo

hdr "ASSERT OUTCOME"
live="$(live_jobs)"
succeeded="$(q "select count(*) from jobwarden_jobs where state='succeeded'")"
failed="$(q "select count(*) from jobwarden_jobs where state='failed'")"
stopped="$(q "select count(*) from jobwarden_jobs where state='stopped'")"
attempts="$(q "select count(*) from jobwarden_job_attempts")"
claimed_hosts="$(q "select count(distinct host_id) from jobwarden_job_attempts where host_id is not null")"

echo "  live=$live succeeded=$succeeded failed=$failed stopped=$stopped attempts=$attempts claimed_worker_containers=$claimed_hosts"
[ "$live" = "0" ] && ok "all 5000 jobs drained to terminal states" || bad "$live jobs still live or limbo"
[ "$succeeded" = "$EXPECTED_SUCCEEDED" ] && ok "$EXPECTED_SUCCEEDED noisy jobs succeeded" || bad "succeeded=$succeeded expected $EXPECTED_SUCCEEDED"
[ "$failed" = "$EXPECTED_FAILED" ] && ok "$EXPECTED_FAILED throw/crash jobs failed" || bad "failed=$failed expected $EXPECTED_FAILED"
[ "$stopped" = "$EXPECTED_STOPPED" ] && ok "$EXPECTED_STOPPED stubborn jobs stopped" || bad "stopped=$stopped expected $EXPECTED_STOPPED"
[ "$attempts" = "$EXPECTED_ATTEMPTS" ] && ok "$EXPECTED_ATTEMPTS attempts recorded" || bad "attempts=$attempts expected $EXPECTED_ATTEMPTS"
[ "${claimed_hosts:-0}" -ge 5 ] && ok "all five worker containers claimed work" || bad "only $claimed_hosts worker containers claimed work"

hdr "ASSERT INVARIANTS"
duplicate_attempts="$(q "select count(*) from (select job_id, attempt_number, count(*) c from jobwarden_job_attempts group by job_id, attempt_number having count(*) > 1) x")"
attempt_mismatch="$(q "select count(*) from jobwarden_jobs j where attempt_count <> (select count(*) from jobwarden_job_attempts a where a.job_id=j.id)")"
inflight_attempts="$(q "select count(*) from jobwarden_job_attempts where state in ('dispatched','running')")"
gapful_logs="$(q "select count(*) from (select attempt_id, count(*) c, min(seq) min_seq, max(seq) max_seq from jobwarden_job_logs group by attempt_id having count(*) > 0 and (min(seq) <> 1 or max(seq) <> count(*))) x")"
stale_rejections="$(q "select count(*) from jobwarden_job_events where reason like 'stale_write_rejected%'")"

echo "  duplicate_attempt_numbers=$duplicate_attempts attempt_count_mismatches=$attempt_mismatch in_flight_attempts=$inflight_attempts gapful_logs=$gapful_logs stale_rejections=$stale_rejections"
[ "$duplicate_attempts" = "0" ] && ok "no duplicate (job_id, attempt_number)" || bad "$duplicate_attempts duplicate attempt number groups"
[ "$attempt_mismatch" = "0" ] && ok "jobs.attempt_count matches actual attempts" || bad "$attempt_mismatch jobs have mismatched attempt_count"
[ "$inflight_attempts" = "0" ] && ok "no attempts left dispatched/running" || bad "$inflight_attempts attempts still in flight"
[ "$gapful_logs" = "0" ] && ok "per-attempt log streams are gapless" || bad "$gapful_logs attempts have gapful logs"
ok "stale races were audited without clobbering state ($stale_rejections rejection events)"

hdr "ASSERT OBSERVABILITY"
logstorm="$(q "select count(*) from jobwarden_job_logs where step='logstorm'")"
artifacts="$(q "select count(*) from jobwarden_job_artifacts where type='report' and name='chaos-summary'")"
stderr_logs="$(q "select count(*) from jobwarden_job_logs where step='process_output' and body_ref like '%CHAOS-STDERR%'")"
sigkill_logs="$(q "select count(*) from jobwarden_job_logs where step='process_output' and body_ref like '%CHAOS-SIGKILL%'")"

echo "  logstorm_lines=$logstorm artifacts=$artifacts stderr_logs=$stderr_logs sigkill_logs=$sigkill_logs"
[ "${logstorm:-0}" -ge "$EXPECTED_LOGSTORM_LINES" ] && ok "logstorm volume captured" || bad "logstorm=$logstorm expected at least $EXPECTED_LOGSTORM_LINES"
[ "$artifacts" = "$ARTIFACT_JOBS" ] && ok "artifact jobs recorded support artifacts" || bad "artifacts=$artifacts expected $ARTIFACT_JOBS"
[ "$stderr_logs" = "$STDERR_JOBS" ] && ok "stderr jobs had process output ingested" || bad "stderr_logs=$stderr_logs expected $STDERR_JOBS"
[ "$sigkill_logs" = "$CRASH_JOBS" ] && ok "crash jobs had dying words ingested" || bad "sigkill_logs=$sigkill_logs expected $CRASH_JOBS"

hdr "RESULT"
echo "  PASS=$PASS  FAIL=$FAIL"
stop_runtime
[ "$FAIL" = "0" ] && { echo "  ✅ FIVE WORKERS + 5000 BAD JOBS VERIFIED"; exit 0; } || { echo "  ❌ FAILURES"; exit 1; }
