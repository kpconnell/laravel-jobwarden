#!/usr/bin/env bash
#
# Curl-only API chaos test.
#
# Assumes the production-like stack is already running at:
#
#   http://localhost:8899/jobwarden/api
#
# Every JobWarden interaction in this script is an HTTP request made with curl:
# dispatch, stop, progress, final inspection, logs, and artifacts. There is no
# artisan dispatch, no docker exec, and no direct database query.
#
# Run from repo root:
#
#   bash tests/integration/api_chaos.sh
set -uo pipefail

API="${JOBWARDEN_API_URL:-http://localhost:8899/jobwarden/api}"
JOB_CLASS="${JOBWARDEN_API_CHAOS_JOB_CLASS:-Workbench\\\\App\\\\Jobs\\\\ChaosMonkeyJob}"
RUN_ID="${JOBWARDEN_API_CHAOS_RUN_ID:-api-chaos-$(date +%s)-$$}"
CURL_CONCURRENCY="${JOBWARDEN_API_CHAOS_CURL_CONCURRENCY:-50}"
CURL_CONNECT_TIMEOUT="${JOBWARDEN_API_CHAOS_CURL_CONNECT_TIMEOUT:-5}"
CURL_MAX_TIME="${JOBWARDEN_API_CHAOS_CURL_MAX_TIME:-20}"
CURL_RETRIES="${JOBWARDEN_API_CHAOS_CURL_RETRIES:-12}"
CURL_RETRY_DELAY="${JOBWARDEN_API_CHAOS_CURL_RETRY_DELAY:-2}"
DRAIN_SAFETY_SECONDS="${JOBWARDEN_API_CHAOS_DRAIN_SAFETY_SECONDS:-1800}"
DEEP_ASSERTIONS="${JOBWARDEN_API_CHAOS_DEEP_ASSERTIONS:-1}"

LOGSTORM_JOBS="${JOBWARDEN_API_CHAOS_LOGSTORM_JOBS:-1500}"
STDERR_JOBS="${JOBWARDEN_API_CHAOS_STDERR_JOBS:-1000}"
ARTIFACT_JOBS="${JOBWARDEN_API_CHAOS_ARTIFACT_JOBS:-1000}"
THROW_JOBS="${JOBWARDEN_API_CHAOS_THROW_JOBS:-1000}"
CRASH_JOBS="${JOBWARDEN_API_CHAOS_CRASH_JOBS:-400}"
STUBBORN_JOBS="${JOBWARDEN_API_CHAOS_STUBBORN_JOBS:-100}"

TARGET_JOBS=$((LOGSTORM_JOBS + STDERR_JOBS + ARTIFACT_JOBS + THROW_JOBS + CRASH_JOBS + STUBBORN_JOBS))
EXPECTED_SUCCEEDED=$((LOGSTORM_JOBS + STDERR_JOBS + ARTIFACT_JOBS))
EXPECTED_FAILED=$((THROW_JOBS + CRASH_JOBS))
EXPECTED_STOPPED="$STUBBORN_JOBS"
EXPECTED_ATTEMPTS=$((LOGSTORM_JOBS + STDERR_JOBS + ARTIFACT_JOBS + (THROW_JOBS * 2) + CRASH_JOBS + STUBBORN_JOBS))
EXPECTED_LOGSTORM_LINES=$((LOGSTORM_JOBS * 20))

PASS=0
FAIL=0
STOPS=0
DISPATCH_FAILURES=0

ok()  { echo "  PASS $1"; PASS=$((PASS+1)); }
bad() { echo "  FAIL $1"; FAIL=$((FAIL+1)); }
hdr() { echo; echo "=================== $1 ==================="; }

api() {
    local method="$1"
    local path="$2"
    local payload="${3:-}"
    local curl_args=(
        --connect-timeout "$CURL_CONNECT_TIMEOUT"
        --max-time "$CURL_MAX_TIME"
        --retry "$CURL_RETRIES"
        --retry-delay "$CURL_RETRY_DELAY"
        --retry-all-errors
        -fsS
        -X "$method"
        "$API$path"
    )

    if [ -n "$payload" ]; then
        curl "${curl_args[@]}" -H 'Content-Type: application/json' -d "$payload"
    else
        curl "${curl_args[@]}"
    fi
}

json_total() {
    php -r '$j=json_decode(stream_get_contents(STDIN), true); echo (int)($j["total"] ?? ($j["meta"]["total"] ?? 0));'
}

json_last_page() {
    php -r '$j=json_decode(stream_get_contents(STDIN), true); echo (int)($j["last_page"] ?? ($j["meta"]["last_page"] ?? 1));'
}

json_ids() {
    php -r '$j=json_decode(stream_get_contents(STDIN), true); foreach (($j["data"] ?? []) as $row) { echo $row["id"], "\n"; }'
}

json_state_count() {
    local state="$1"
    php -r '$state=$argv[1]; $j=json_decode(stream_get_contents(STDIN), true); $n=0; foreach (($j["data"] ?? []) as $row) { if (($row["state"] ?? null) === $state) { $n++; } } echo $n;' "$state"
}

json_attempt_sum() {
    php -r '$j=json_decode(stream_get_contents(STDIN), true); $n=0; foreach (($j["data"] ?? []) as $row) { $n += (int)($row["attempt_count"] ?? 0); } echo $n;'
}

json_stubborn_running_ids() {
    php -r '
        $j=json_decode(stream_get_contents(STDIN), true);
        foreach (($j["data"] ?? []) as $row) {
            $mode = $row["params"]["mode"] ?? null;
            if (($row["state"] ?? null) === "running" && $mode === "stubborn" && empty($row["cancel_requested"])) {
                echo $row["id"], "\n";
            }
        }
    '
}

job_query() {
    local page="${1:-1}"
    local extra="${2:-}"
    api GET "/jobs?name=$RUN_ID&per_page=200&page=$page$extra"
}

job_total() {
    job_query 1 | json_total
}

state_total() {
    local state="$1"
    job_query 1 "&state=$state" | json_total
}

all_job_ids() {
    local first last page
    first="$(job_query 1)"
    last="$(printf '%s' "$first" | json_last_page)"
    printf '%s' "$first" | json_ids
    page=2
    while [ "$page" -le "${last:-1}" ]; do
        job_query "$page" | json_ids
        page=$((page+1))
    done
}

sum_attempt_counts() {
    local first last page sum chunk
    first="$(job_query 1)"
    last="$(printf '%s' "$first" | json_last_page)"
    sum="$(printf '%s' "$first" | json_attempt_sum)"
    page=2
    while [ "$page" -le "${last:-1}" ]; do
        chunk="$(job_query "$page" | json_attempt_sum)"
        sum=$((sum + chunk))
        page=$((page+1))
    done
    echo "$sum"
}

dispatch_one() {
    local mode="$1"
    local attempts="$2"
    local priority="$3"
    local idempotent="$4"
    local param_tail="${5:-}"
    local payload

    payload="{\"job_class\":\"$JOB_CLASS\",\"name\":\"$RUN_ID\",\"params\":{\"mode\":\"$mode\",\"chaos_run_id\":\"$RUN_ID\"$param_tail},\"max_attempts\":$attempts,\"priority\":$priority,\"idempotent\":$idempotent}"
    api POST /jobs "$payload" >/dev/null
}

dispatch_many() {
    local mode="$1"
    local count="$2"
    local attempts="$3"
    local priority="$4"
    local idempotent="$5"
    local param_tail="${6:-}"
    local launched=0
    local total=0
    local pids=""
    local pid

    [ "$count" -le 0 ] && return 0

    while [ "$total" -lt "$count" ]; do
        (
            dispatch_one "$mode" "$attempts" "$priority" "$idempotent" "$param_tail" || exit 1
        ) &
        pid="$!"
        pids="$pids $pid"
        launched=$((launched+1))
        total=$((total+1))

        if [ "$launched" -ge "$CURL_CONCURRENCY" ]; then
            for pid in $pids; do
                wait "$pid" || DISPATCH_FAILURES=$((DISPATCH_FAILURES+1))
            done
            pids=""
            launched=0
            printf "  dispatched %-9s %5s/%-5s\r" "$mode" "$total" "$count"
        fi
    done

    for pid in $pids; do
        wait "$pid" || DISPATCH_FAILURES=$((DISPATCH_FAILURES+1))
    done
    echo "  dispatched $mode $count"
}

stop_stubborn_jobs() {
    local first last page ids id

    first="$(job_query 1 "&state=running")"
    last="$(printf '%s' "$first" | json_last_page)"
    ids="$(printf '%s' "$first" | json_stubborn_running_ids)"

    page=2
    while [ "$page" -le "${last:-1}" ]; do
        ids="$ids
$(job_query "$page" "&state=running" | json_stubborn_running_ids)"
        page=$((page+1))
    done

    [ -z "$(echo "$ids" | sed '/^[[:space:]]*$/d')" ] && return 0

    while IFS= read -r id; do
        id="$(echo "$id" | tr -d '[:space:]')"
        if [ -n "$id" ]; then
            api POST "/jobs/$id/stop" '{"reason":"api chaos stop"}' >/dev/null && STOPS=$((STOPS+1))
        fi
    done <<< "$ids"
}

live_jobs() {
    local pending queued retrying running orphaned
    pending="$(state_total pending)"
    queued="$(state_total queued)"
    retrying="$(state_total retrying)"
    running="$(state_total running)"
    orphaned="$(state_total orphaned)"
    echo $((pending + queued + retrying + running + orphaned))
}

print_progress() {
    printf "  run=%s jobs=%5s/%-5s live=%5s succ=%5s fail=%5s stopped=%4s stops=%4s\r" \
        "$RUN_ID" "$(job_total)" "$TARGET_JOBS" "$(live_jobs)" \
        "$(state_total succeeded)" "$(state_total failed)" "$(state_total stopped)" "$STOPS"
}

assert_run_still_present() {
    local jobs
    jobs="$(job_total)"
    if [ "$jobs" != "$TARGET_JOBS" ]; then
        bad "run visibility changed: API sees $jobs jobs for $RUN_ID, expected $TARGET_JOBS"
        echo "  This usually means the backing database/schema was reset while the test was running."
        exit 1
    fi
}

deep_assertions() {
    local ids id job logs inspected=0 attempts=0 mismatches=0 artifacts=0 logstorm=0 stderr_logs=0 sigkill_logs=0

    hdr "DEEP CURL INSPECTION"
    ids="$(all_job_ids)"

    while IFS= read -r id; do
        id="$(echo "$id" | tr -d '[:space:]')"
        [ -z "$id" ] && continue

        job="$(api GET "/jobs/$id")"
        logs="$(api GET "/jobs/$id/logs?limit=200")"

        eval "$(
            JOB_JSON="$job" LOG_JSON="$logs" php <<'PHP'
<?php
$job = json_decode(getenv('JOB_JSON'), true);
$logs = json_decode(getenv('LOG_JSON'), true);
$attempts = count($job['attempts'] ?? []);
$mismatch = 0;
$jobState = $job['state'] ?? null;
$attemptStates = array_map(static fn ($a) => $a['state'] ?? null, $job['attempts'] ?? []);

if ($jobState === 'running' && ! in_array('running', $attemptStates, true) && ! in_array('dispatched', $attemptStates, true)) {
    $mismatch = 1;
}
if ($jobState === 'succeeded' && ! in_array('succeeded', $attemptStates, true)) {
    $mismatch = 1;
}

$artifacts = 0;
foreach (($job['artifacts'] ?? []) as $artifact) {
    if (($artifact['type'] ?? null) === 'report' && ($artifact['name'] ?? null) === 'chaos-summary') {
        $artifacts++;
    }
}

$logstorm = 0;
$stderr = 0;
$sigkill = 0;
foreach (($logs ?? []) as $log) {
    if (($log['step'] ?? null) === 'logstorm') {
        $logstorm++;
    }
    if (($log['step'] ?? null) === 'process_output' && str_contains((string) ($log['body_ref'] ?? ''), 'CHAOS-STDERR')) {
        $stderr++;
    }
    if (($log['step'] ?? null) === 'process_output' && str_contains((string) ($log['body_ref'] ?? ''), 'CHAOS-SIGKILL')) {
        $sigkill++;
    }
}

echo "job_attempts={$attempts}\n";
echo "job_mismatch={$mismatch}\n";
echo "job_artifacts={$artifacts}\n";
echo "job_logstorm={$logstorm}\n";
echo "job_stderr={$stderr}\n";
echo "job_sigkill={$sigkill}\n";
PHP
        )"

        attempts=$((attempts + job_attempts))
        mismatches=$((mismatches + job_mismatch))
        artifacts=$((artifacts + job_artifacts))
        logstorm=$((logstorm + job_logstorm))
        stderr_logs=$((stderr_logs + job_stderr))
        sigkill_logs=$((sigkill_logs + job_sigkill))
        inspected=$((inspected+1))

        if [ $((inspected % 200)) -eq 0 ]; then
            printf "  inspected=%5s/%-5s attempts=%6s artifacts=%5s logstorm=%7s\r" "$inspected" "$TARGET_JOBS" "$attempts" "$artifacts" "$logstorm"
        fi
    done <<< "$ids"
    echo

    echo "  inspected=$inspected attempts=$attempts parent_child_mismatches=$mismatches artifacts=$artifacts logstorm_lines=$logstorm stderr_logs=$stderr_logs sigkill_logs=$sigkill_logs"
    [ "$inspected" = "$TARGET_JOBS" ] && ok "all API-created jobs were inspected over HTTP" || bad "inspected=$inspected expected $TARGET_JOBS"
    [ "$attempts" = "$EXPECTED_ATTEMPTS" ] && ok "$EXPECTED_ATTEMPTS attempts visible through API" || bad "attempts=$attempts expected $EXPECTED_ATTEMPTS"
    [ "$mismatches" = "0" ] && ok "no terminal attempt/nonterminal parent mismatch via API" || bad "$mismatches parent/attempt mismatches"
    [ "$artifacts" = "$ARTIFACT_JOBS" ] && ok "artifact jobs visible through API" || bad "artifacts=$artifacts expected $ARTIFACT_JOBS"
    [ "${logstorm:-0}" -ge "$EXPECTED_LOGSTORM_LINES" ] && ok "logstorm volume visible through API logs" || bad "logstorm=$logstorm expected at least $EXPECTED_LOGSTORM_LINES"
    [ "$stderr_logs" = "$STDERR_JOBS" ] && ok "stderr output visible through API logs" || bad "stderr_logs=$stderr_logs expected $STDERR_JOBS"
    [ "$sigkill_logs" = "$CRASH_JOBS" ] && ok "SIGKILL output visible through API logs" || bad "sigkill_logs=$sigkill_logs expected $CRASH_JOBS"
}

hdr "CHECK API"
echo "  api=$API"
echo "  run_id=$RUN_ID"
echo "  curl_concurrency=$CURL_CONCURRENCY"
echo "  curl_timeout=${CURL_CONNECT_TIMEOUT}s/${CURL_MAX_TIME}s retries=$CURL_RETRIES delay=${CURL_RETRY_DELAY}s"
api GET /stats >/dev/null || { bad "API is not reachable at $API"; exit 1; }
ok "operator API is reachable"

workers="$(api GET '/workers?role=supervisor' | php -r '$j=json_decode(stream_get_contents(STDIN), true); $hosts=[]; foreach (($j ?? []) as $w) { if (($w["state"] ?? null) === "active") { $hosts[$w["host_id"] ?? ""] = true; } } echo count($hosts);')"
echo "  active supervisor hosts=$workers"
[ "${workers:-0}" -ge 2 ] && ok "real supervisor containers are active" || bad "only $workers active supervisor host(s)"

hdr "QUEUE 5000 JOBS WITH 5000 CURL REQUESTS"
existing_jobs="$(job_total)"
if [ "$existing_jobs" = "0" ]; then
    dispatch_many logstorm "$LOGSTORM_JOBS" 1 60 false ', "lines":20'
    dispatch_many stderr "$STDERR_JOBS" 1 50 false ', "lines":8'
    dispatch_many artifact "$ARTIFACT_JOBS" 1 40 false ', "payload_bytes":512'
    dispatch_many throw "$THROW_JOBS" 2 30 true
    dispatch_many crash "$CRASH_JOBS" 1 20 false
    dispatch_many stubborn "$STUBBORN_JOBS" 1 100 false ', "seconds":300'
elif [ "$existing_jobs" = "$TARGET_JOBS" ]; then
    echo "  found existing run with $existing_jobs jobs; resuming drain/inspection"
else
    bad "run_id already has $existing_jobs jobs, expected 0 or $TARGET_JOBS"
fi

[ "$DISPATCH_FAILURES" = "0" ] && ok "all curl dispatch batches completed" || bad "$DISPATCH_FAILURES curl dispatch batch(es) failed"

jobs="$(job_total)"
echo "  api-visible jobs=$jobs expected=$TARGET_JOBS"
[ "$jobs" = "$TARGET_JOBS" ] && ok "exact job count was queued through individual curl requests" || bad "queued $jobs jobs, expected $TARGET_JOBS"

hdr "DRAIN VIA CURL"
deadline=$((SECONDS+DRAIN_SAFETY_SECONDS))
while [ $SECONDS -lt $deadline ]; do
    assert_run_still_present
    stop_stubborn_jobs
    live="$(live_jobs)"
    print_progress
    [ "${live:-0}" = "0" ] && break
    sleep 2
done
echo

hdr "ASSERT CURL-OBSERVED OUTCOME"
live="$(live_jobs)"
succeeded="$(state_total succeeded)"
failed="$(state_total failed)"
stopped="$(state_total stopped)"
attempts="$(sum_attempt_counts)"

echo "  live=$live succeeded=$succeeded failed=$failed stopped=$stopped attempts=$attempts"
[ "$live" = "0" ] && ok "all API chaos jobs drained to terminal states" || bad "$live API chaos jobs still live or limbo"
[ "$succeeded" = "$EXPECTED_SUCCEEDED" ] && ok "$EXPECTED_SUCCEEDED noisy jobs succeeded" || bad "succeeded=$succeeded expected $EXPECTED_SUCCEEDED"
[ "$failed" = "$EXPECTED_FAILED" ] && ok "$EXPECTED_FAILED throw/crash jobs failed" || bad "failed=$failed expected $EXPECTED_FAILED"
[ "$stopped" = "$EXPECTED_STOPPED" ] && ok "$EXPECTED_STOPPED stubborn jobs stopped through curl" || bad "stopped=$stopped expected $EXPECTED_STOPPED"
[ "$attempts" = "$EXPECTED_ATTEMPTS" ] && ok "$EXPECTED_ATTEMPTS attempts reflected on job read models" || bad "attempt_count_sum=$attempts expected $EXPECTED_ATTEMPTS"

if [ "$DEEP_ASSERTIONS" = "1" ]; then
    deep_assertions
else
    echo "  skipping deep per-job curl inspection because JOBWARDEN_API_CHAOS_DEEP_ASSERTIONS=$DEEP_ASSERTIONS"
fi

hdr "RESULT"
echo "  PASS=$PASS  FAIL=$FAIL"
[ "$FAIL" = "0" ] && { echo "  CURL API CHAOS VERIFIED"; exit 0; } || { echo "  FAILURES"; exit 1; }
