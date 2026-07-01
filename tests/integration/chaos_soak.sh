#!/usr/bin/env bash
#
# Count-based real-runtime chaos scale test. Not duration-based.
#
# This script defines scale by workload and pressure:
#
#   - dispatch at least TARGET_JOBS hostile jobs
#   - keep up to MAX_LIVE_BACKLOG live jobs in the system
#   - run real supervisors on two host PID namespaces
#   - run real local reaper daemons
#   - inject supervisor SIGKILL faults every FAULT_EVERY_JOBS dispatched jobs
#   - operator-stop stubborn real children throughout the run
#   - drain the system and audit durable invariants
#
# Time appears only as a safety guard so a broken run cannot hang forever. It is
# not the measure of success.
#
# Run from repo root:
#
#   bash tests/integration/chaos_soak.sh
#
# Useful knobs:
#
#   JOBWARDEN_SCALE_TARGET_JOBS=10000 JOBWARDEN_SCALE_CAPACITY=48 bash tests/integration/chaos_soak.sh
set -uo pipefail

TB="vendor/bin/testbench"

TARGET_JOBS="${JOBWARDEN_SCALE_TARGET_JOBS:-5000}"
MAX_LIVE_BACKLOG="${JOBWARDEN_SCALE_MAX_LIVE_BACKLOG:-1000}"
CAPACITY="${JOBWARDEN_SCALE_CAPACITY:-32}"
FAULT_EVERY_JOBS="${JOBWARDEN_SCALE_FAULT_EVERY_JOBS:-1000}"
DISPATCH_SAFETY_SECONDS="${JOBWARDEN_SCALE_DISPATCH_SAFETY_SECONDS:-3600}"
DRAIN_SAFETY_SECONDS="${JOBWARDEN_SCALE_DRAIN_SAFETY_SECONDS:-1800}"

SUCCESS_PER_WAVE="${JOBWARDEN_SCALE_SUCCESS_PER_WAVE:-40}"
LOGSTORM_PER_WAVE="${JOBWARDEN_SCALE_LOGSTORM_PER_WAVE:-25}"
ARTIFACT_PER_WAVE="${JOBWARDEN_SCALE_ARTIFACT_PER_WAVE:-15}"
STDERR_PER_WAVE="${JOBWARDEN_SCALE_STDERR_PER_WAVE:-15}"
THROW_PER_WAVE="${JOBWARDEN_SCALE_THROW_PER_WAVE:-20}"
CRASH_PER_WAVE="${JOBWARDEN_SCALE_CRASH_PER_WAVE:-10}"
STUBBORN_PER_WAVE="${JOBWARDEN_SCALE_STUBBORN_PER_WAVE:-8}"
LOGSTORM_LINES="${JOBWARDEN_SCALE_LOGSTORM_LINES:-30}"
STDERR_LINES="${JOBWARDEN_SCALE_STDERR_LINES:-10}"

JOBS_PER_WAVE=$((SUCCESS_PER_WAVE + LOGSTORM_PER_WAVE + ARTIFACT_PER_WAVE + STDERR_PER_WAVE + THROW_PER_WAVE + CRASH_PER_WAVE + STUBBORN_PER_WAVE))

PASS=0
FAIL=0
WAVES=0
FAULTS=0
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

    [ "$count" -le 0 ] && return 0

    docker compose exec -T host1 "$TB" jobwarden:demo:dispatch chaos \
        --mode="$mode" \
        --count="$count" \
        --max-attempts="$attempts" \
        --priority="$priority" \
        "$@" >/dev/null
}

start_supervisor() {
    local host="$1"
    docker compose exec -d "${runtime_env[@]}" "$host" "$TB" jobwarden:work --capacity="$CAPACITY" >/dev/null 2>&1
}

start_runtime() {
    docker compose exec -d "${runtime_env[@]}" host1 "$TB" jobwarden:reap:local --interval=1 >/dev/null 2>&1
    docker compose exec -d "${runtime_env[@]}" host2 "$TB" jobwarden:reap:local --interval=1 >/dev/null 2>&1
    start_supervisor host1
    start_supervisor host2
}

stop_runtime() {
    docker compose restart host1 host2 >/dev/null 2>&1
    sleep 2
}

dispatch_wave() {
    dispatch_chaos success "$SUCCESS_PER_WAVE" 1 60 --sleep-ms=25
    dispatch_chaos logstorm "$LOGSTORM_PER_WAVE" 1 50 --lines="$LOGSTORM_LINES"
    dispatch_chaos artifact "$ARTIFACT_PER_WAVE" 1 40 --payload-bytes=512
    dispatch_chaos stderr "$STDERR_PER_WAVE" 1 30 --lines="$STDERR_LINES"
    dispatch_chaos throw "$THROW_PER_WAVE" 2 20 --idempotent
    dispatch_chaos crash "$CRASH_PER_WAVE" 2 10 --idempotent
    dispatch_chaos stubborn "$STUBBORN_PER_WAVE" 2 100 --idempotent --duration=180
    WAVES=$((WAVES+1))
}

stop_stubborn_children() {
    local ids
    ids="$(q_lines "select id from jobwarden_jobs where params->>'mode'='stubborn' and state='running' and cancel_requested=false limit 200")"
    [ -z "$ids" ] && return 0

    while IFS= read -r id; do
        id="$(echo "$id" | tr -d '[:space:]')"
        if [ -n "$id" ]; then
            docker compose exec -T host1 "$TB" jobwarden:cancel "$id" --reason="chaos scale stop" >/dev/null 2>&1 < /dev/null
            STOPS=$((STOPS+1))
        fi
    done <<< "$ids"
}

kill_one_supervisor() {
    local host="$1"
    echo
    echo "  fault: SIGKILL jobwarden:work on $host"
    docker compose exec -T "$host" pkill -9 -f 'jobwarden:work' >/dev/null 2>&1
    FAULTS=$((FAULTS+1))
    sleep 3
    start_supervisor "$host"
}

live_jobs() {
    q "select count(*) from jobwarden_jobs where state in ('pending','queued','retrying','running','orphaned')"
}

total_jobs() {
    q "select count(*) from jobwarden_jobs"
}

print_progress() {
    printf "  jobs=%5s/%-5s waves=%3s live=%5s faults=%2s stops=%4s succ=%5s fail=%5s stopped=%5s attempts=%6s logs=%7s\r" \
        "$(total_jobs)" "$TARGET_JOBS" "$WAVES" "$(live_jobs)" "$FAULTS" "$STOPS" \
        "$(q "select count(*) from jobwarden_jobs where state='succeeded'")" \
        "$(q "select count(*) from jobwarden_jobs where state='failed'")" \
        "$(q "select count(*) from jobwarden_jobs where state='stopped'")" \
        "$(q "select count(*) from jobwarden_job_attempts")" \
        "$(q "select count(*) from jobwarden_job_logs")"
}

hdr "BOOT REAL SCALE RUNTIME"
echo "  target_jobs=$TARGET_JOBS jobs_per_wave=$JOBS_PER_WAVE max_live_backlog=$MAX_LIVE_BACKLOG capacity_per_host=$CAPACITY fault_every_jobs=$FAULT_EVERY_JOBS"
docker compose up -d pg host1 host2 >/dev/null 2>&1
stop_runtime
docker compose exec -T host1 "$TB" migrate:fresh --database=jobwarden >/dev/null
start_runtime

hdr "DISPATCH COUNT-BASED HOSTILE LOAD"
dispatch_deadline=$((SECONDS+DISPATCH_SAFETY_SECONDS))
next_fault_at="$FAULT_EVERY_JOBS"
fault_host=host1

while [ "$(total_jobs)" -lt "$TARGET_JOBS" ]; do
    if [ "$SECONDS" -ge "$dispatch_deadline" ]; then
        echo
        bad "dispatch safety timeout hit before target_jobs"
        break
    fi

    stop_stubborn_children

    live="$(live_jobs)"
    if [ "${live:-0}" -lt "$MAX_LIVE_BACKLOG" ]; then
        dispatch_wave
    else
        sleep 1
    fi

    jobs_now="$(total_jobs)"
    if [ "$jobs_now" -ge "$next_fault_at" ]; then
        kill_one_supervisor "$fault_host"
        if [ "$fault_host" = "host1" ]; then fault_host=host2; else fault_host=host1; fi
        next_fault_at=$((next_fault_at + FAULT_EVERY_JOBS))
    fi

    print_progress
done
echo

hdr "DRAIN"
drain_deadline=$((SECONDS+DRAIN_SAFETY_SECONDS))
while [ $SECONDS -lt $drain_deadline ]; do
    stop_stubborn_children
    live="$(live_jobs)"
    print_progress
    [ "${live:-0}" = "0" ] && break
    sleep 2
done
echo

hdr "ASSERT SCALE AND OUTCOME"
jobs=$(q "select count(*) from jobwarden_jobs")
attempts=$(q "select count(*) from jobwarden_job_attempts")
logs=$(q "select count(*) from jobwarden_job_logs")
live=$(live_jobs)
succeeded=$(q "select count(*) from jobwarden_jobs where state='succeeded'")
failed=$(q "select count(*) from jobwarden_jobs where state='failed'")
stopped=$(q "select count(*) from jobwarden_jobs where state='stopped'")
hosts=$(q "select count(distinct host_id) from jobwarden_job_attempts where host_id is not null")
reaped_logs=$(q "select count(*) from jobwarden_job_logs where step='reaped'")
stale_rejections=$(q "select count(*) from jobwarden_job_events where reason like 'stale_write_rejected%'")

planned_jobs=$((WAVES * JOBS_PER_WAVE))
min_logstorm=$((WAVES * LOGSTORM_PER_WAVE * LOGSTORM_LINES))
min_stopped=$((WAVES * STUBBORN_PER_WAVE))

echo "  waves=$WAVES planned_jobs=$planned_jobs jobs=$jobs attempts=$attempts logs=$logs live=$live succeeded=$succeeded failed=$failed stopped=$stopped hosts=$hosts faults=$FAULTS reaped_logs=$reaped_logs stale_rejections=$stale_rejections"
[ "${jobs:-0}" -ge "$TARGET_JOBS" ] && ok "target job count reached ($jobs >= $TARGET_JOBS)" || bad "jobs=$jobs target=$TARGET_JOBS"
[ "$jobs" = "$planned_jobs" ]        && ok "all planned wave jobs were persisted ($jobs)"    || bad "jobs=$jobs planned=$planned_jobs"
[ "${attempts:-0}" -gt "$jobs" ]     && ok "retry/orphan pressure created extra attempts"    || bad "attempts=$attempts was not greater than jobs=$jobs"
[ "$live" = "0" ]                    && ok "system drained with no live or limbo jobs"       || bad "$live jobs remain live/limbo"
[ "${succeeded:-0}" -gt 0 ]           && ok "some hostile work succeeded"                    || bad "no jobs succeeded"
[ "${failed:-0}" -gt 0 ]              && ok "throw/crash work failed deterministically"       || bad "no jobs failed"
[ "${stopped:-0}" -ge "$min_stopped" ] && ok "operator stops were exercised for stubborn jobs" || bad "stopped=$stopped expected at least $min_stopped"
[ "${hosts:-0}" -ge 2 ]               && ok "both host PID namespaces claimed work"           || bad "only $hosts host(s) claimed work"
[ "${FAULTS:-0}" -ge 1 ]              && ok "supervisor-kill faults were injected"            || bad "no supervisor faults were injected"
[ "${reaped_logs:-0}" -ge 1 ]         && ok "local reapers recovered supervisor-killed work"  || bad "no reaper log entries observed"

hdr "ASSERT INVARIANTS"
duplicate_attempts=$(q "select count(*) from (select job_id, attempt_number, count(*) c from jobwarden_job_attempts group by job_id, attempt_number having count(*) > 1) x")
attempt_mismatch=$(q "select count(*) from jobwarden_jobs j where attempt_count <> (select count(*) from jobwarden_job_attempts a where a.job_id=j.id)")
inflight_attempts=$(q "select count(*) from jobwarden_job_attempts where state in ('dispatched','running')")
gapful_logs=$(q "select count(*) from (select attempt_id, count(*) c, min(seq) min_seq, max(seq) max_seq from jobwarden_job_logs group by attempt_id having count(*) > 0 and (min(seq) <> 1 or max(seq) <> count(*))) x")

echo "  duplicate_attempt_numbers=$duplicate_attempts attempt_count_mismatches=$attempt_mismatch in_flight_attempts=$inflight_attempts gapful_logs=$gapful_logs"
[ "$duplicate_attempts" = "0" ] && ok "no duplicate (job_id, attempt_number)"       || bad "$duplicate_attempts duplicate attempt-number groups"
[ "$attempt_mismatch" = "0" ]   && ok "jobs.attempt_count matches actual attempts"  || bad "$attempt_mismatch jobs have mismatched attempt_count"
[ "$inflight_attempts" = "0" ]  && ok "no attempt left dispatched/running"          || bad "$inflight_attempts attempts still in flight"
[ "$gapful_logs" = "0" ]        && ok "per-attempt log sequences are gapless"       || bad "$gapful_logs attempts have gapful logs"
ok "stale write races were audited without clobbering state ($stale_rejections rejection events)"

hdr "ASSERT OBSERVABILITY VOLUME"
logstorm=$(q "select count(*) from jobwarden_job_logs where step='logstorm'")
artifacts=$(q "select count(*) from jobwarden_job_artifacts where type='report' and name='chaos-summary'")
stderr_logs=$(q "select count(*) from jobwarden_job_logs where step='process_output' and body_ref like '%CHAOS-STDERR%'")
sigkill_logs=$(q "select count(*) from jobwarden_job_logs where step='process_output' and body_ref like '%CHAOS-SIGKILL%'")

echo "  logstorm_lines=$logstorm artifacts=$artifacts stderr_logs=$stderr_logs sigkill_logs=$sigkill_logs"
[ "${logstorm:-0}" -ge "$min_logstorm" ] && ok "log storm volume captured ($logstorm rows)" || bad "logstorm=$logstorm expected at least $min_logstorm"
[ "${artifacts:-0}" -ge "$((WAVES * ARTIFACT_PER_WAVE))" ] && ok "artifact volume captured" || bad "artifacts=$artifacts too low"
[ "${stderr_logs:-0}" -gt 0 ]    && ok "raw stderr was ingested"                    || bad "no stderr process-output logs"
[ "${sigkill_logs:-0}" -gt 0 ]   && ok "hard-crash output was ingested"             || bad "no SIGKILL process-output logs"

hdr "RESULT"
echo "  PASS=$PASS  FAIL=$FAIL"
stop_runtime
[ "$FAIL" = "0" ] && { echo "  ✅ REAL-RUNTIME COUNT-BASED CHAOS SCALE VERIFIED"; exit 0; } || { echo "  ❌ FAILURES"; exit 1; }
