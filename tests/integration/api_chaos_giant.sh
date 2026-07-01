#!/usr/bin/env bash
#
# Giant curl-only API chaos test — restart-survival edition.
#
# Goal: prove 100% job fidelity ("no job left behind") for a LARGE population of
# jobs spread across MANY hosts, THROUGH a full Docker restart of the running
# fleet. Everything the test asserts is read back over the operator HTTP API
# (curl only): dispatch, drain, restart chaos, and the final fidelity ledger.
#
# Why every cohort here is idempotent (unlike api_chaos.sh): recovery only
# auto-retries idempotent jobs (RecoveryService::canRetry = idempotent AND
# attempt_count < max_attempts). A mid-flight restart orphans whatever was
# in-flight (Tier-3 global reaper, keyed on worker_id); an idempotent job then
# mints a fresh attempt and converges to a DETERMINISTIC terminal state:
#
#   * SUCCEED cohorts (success/logstorm/artifact/stderr) -> always `succeeded`
#   * FAIL cohorts    (throw/crash, bounded max_attempts) -> always `failed`
#
# So the terminal counts are restart-INVARIANT: the exact same ledger must hold
# whether or not a restart happened. If recovery were broken, in-flight jobs
# would park in `orphaned` forever and `live` would never reach 0 -> loud fail.
#
# Run from repo root (the fleet must already be up, scaled to N hosts):
#
#   docker compose up -d --scale host=10
#   bash tests/integration/api_chaos_giant.sh
#
# Tunables (env): JOBWARDEN_GIANT_SCALE lets you shrink for a fast smoke run.
set -uo pipefail

API="${JOBWARDEN_API_URL:-http://localhost:8899/jobwarden/api}"
JOB_CLASS="${JOBWARDEN_API_CHAOS_JOB_CLASS:-App\\\\Jobs\\\\ChaosMonkeyJob}"
# Plain (single-backslash) class name for the PHP bulk-body generator — json_encode
# escapes the backslashes itself, so we must NOT pre-escape them here.
JOB_CLASS_PLAIN="${JOBWARDEN_API_CHAOS_JOB_CLASS_PLAIN:-App\Jobs\ChaosMonkeyJob}"
RUN_ID="${JOBWARDEN_API_CHAOS_RUN_ID:-giant-$(date +%s)-$$}"

# ---- curl behaviour -------------------------------------------------------
# High client concurrency keeps the (single) API server's queue full; retries
# ride out the window where the API/db are mid-restart.
# A single-threaded `php artisan serve` serializes requests, so HIGH client
# concurrency just overflows its accept backlog → connection-reset storms →
# retry stalls (dispatch crawls AND the dashboard UI starves). Lower concurrency
# keeps one request in flight with a small backlog and is measurably FASTER here
# (benchmarked ~500 req/s at 16 vs ~250 at 32). Raise only behind a concurrent
# server (fpm/frankenphp/multiple replicas).
CURL_CONCURRENCY="${JOBWARDEN_API_CHAOS_CURL_CONCURRENCY:-16}"
CURL_CONNECT_TIMEOUT="${JOBWARDEN_API_CHAOS_CURL_CONNECT_TIMEOUT:-5}"
CURL_MAX_TIME="${JOBWARDEN_API_CHAOS_CURL_MAX_TIME:-30}"
CURL_RETRIES="${JOBWARDEN_API_CHAOS_CURL_RETRIES:-40}"
CURL_RETRY_DELAY="${JOBWARDEN_API_CHAOS_CURL_RETRY_DELAY:-2}"

# ---- drain / recovery budget ----------------------------------------------
DRAIN_SAFETY_SECONDS="${JOBWARDEN_API_CHAOS_DRAIN_SAFETY_SECONDS:-3600}"
SAMPLE_SIZE="${JOBWARDEN_API_CHAOS_SAMPLE_SIZE:-300}"

# ---- restart chaos --------------------------------------------------------
# stack  = docker compose restart          (db + hosts + dashboard: the real thing)
# hosts  = docker compose restart host     (worker fleet only, db/API stay up)
# none   = skip the restart (pure scale test)
RESTART_MODE="${JOBWARDEN_API_CHAOS_RESTART_MODE:-stack}"
RESTART_CMD="${JOBWARDEN_API_CHAOS_RESTART_CMD:-docker compose}"
# Fire the restart once this fraction of the target has reached a terminal
# state (work is demonstrably flowing, plenty still in-flight to orphan).
RESTART_AT_FRACTION="${JOBWARDEN_API_CHAOS_RESTART_AT_FRACTION:-8}"   # trigger at ~1/8 done

# ---- population (defaults sum to 100,000) ---------------------------------
# JOBWARDEN_GIANT_SCALE scales every cohort down for a smoke run, e.g. SCALE=0.02
SCALE="${JOBWARDEN_GIANT_SCALE:-1}"
scale() { php -r 'echo max((int)$argv[2], (int)round($argv[1]*$argv[3]));' "$1" "$2" "$SCALE"; }

SUCCESS_JOBS="${JOBWARDEN_GIANT_SUCCESS_JOBS:-$(scale 50000 0)}"
LOGSTORM_JOBS="${JOBWARDEN_GIANT_LOGSTORM_JOBS:-$(scale 20000 0)}"
ARTIFACT_JOBS="${JOBWARDEN_GIANT_ARTIFACT_JOBS:-$(scale 12000 0)}"
STDERR_JOBS="${JOBWARDEN_GIANT_STDERR_JOBS:-$(scale 8000 0)}"
THROW_JOBS="${JOBWARDEN_GIANT_THROW_JOBS:-$(scale 6000 0)}"
CRASH_JOBS="${JOBWARDEN_GIANT_CRASH_JOBS:-$(scale 4000 0)}"

# A slice of the success cohort is dispatched NON-idempotent, to exercise the
# OTHER half of the guard: when the restart orphans one of these, recovery must
# NOT auto-retry it — the lost outcome is indeterminate, so it PARKS in `orphaned`
# for an operator (spec §11a). Carved out of SUCCESS_JOBS so the total is unchanged.
NONIDEMPOTENT_PCT="${JOBWARDEN_GIANT_NONIDEMPOTENT_PCT:-10}"
NONIDEM_JOBS=$(php -r 'echo (int) round($argv[1] * $argv[2] / 100);' "$SUCCESS_JOBS" "$NONIDEMPOTENT_PCT")
SUCCESS_JOBS=$((SUCCESS_JOBS - NONIDEM_JOBS))

# Retry budgets. SUCCEED cohorts need headroom to survive being orphaned by the
# restart (each orphan burns one attempt); FAIL cohorts are bounded so they
# deterministically exhaust to `failed`.
SUCCEED_MAX_ATTEMPTS="${JOBWARDEN_GIANT_SUCCEED_MAX_ATTEMPTS:-5}"
FAIL_MAX_ATTEMPTS="${JOBWARDEN_GIANT_FAIL_MAX_ATTEMPTS:-3}"

# Give the bulk `success` cohort a real runtime so worker slots stay occupied and
# the fleet is visibly busy (not instant no-ops that drain the moment they land).
# It also means the mid-flight restart catches thousands of genuinely-running
# jobs. 0 = instant.
SUCCESS_SLEEP_MS="${JOBWARDEN_GIANT_SUCCESS_SLEEP_MS:-2500}"

# Bulk dispatch: how many jobs per POST /jobs body, and how many bodies in flight.
# One bulk POST pays a single framework bootstrap for the whole batch, so this is
# what turns dispatch from the bottleneck into a non-event. Keep <= the API's
# jobwarden.api.max_bulk (default 2000). BULK_CONCURRENCY ~= dashboard serve workers.
BULK_SIZE="${JOBWARDEN_GIANT_BULK_SIZE:-500}"
BULK_CONCURRENCY="${JOBWARDEN_GIANT_BULK_CONCURRENCY:-8}"

TARGET_JOBS=$((SUCCESS_JOBS + NONIDEM_JOBS + LOGSTORM_JOBS + ARTIFACT_JOBS + STDERR_JOBS + THROW_JOBS + CRASH_JOBS))
# Idempotent successes ALWAYS converge (they retry through any orphaning).
EXPECTED_IDEMPOTENT_SUCCEEDED=$((SUCCESS_JOBS + LOGSTORM_JOBS + ARTIFACT_JOBS + STDERR_JOBS))
EXPECTED_FAILED=$((THROW_JOBS + CRASH_JOBS))
# Non-idempotent successes split: those the restart didn't catch succeed; those it
# caught in-flight PARK in `orphaned`. So `succeeded` is a RANGE, not a fixed number:
#   EXPECTED_IDEMPOTENT_SUCCEEDED  <=  succeeded  <=  EXPECTED_SUCCEEDED_MAX
EXPECTED_SUCCEEDED_MAX=$((EXPECTED_IDEMPOTENT_SUCCEEDED + NONIDEM_JOBS))
RESTART_AT=$((TARGET_JOBS / RESTART_AT_FRACTION))

PASS=0
FAIL=0
DISPATCH_FAILURES=0
RESTARTED=0
PEAK_ORPHANED=0
PEAK_RETRYING=0
PEAK_LIVE=0

ok()  { echo "  PASS $1"; PASS=$((PASS+1)); }
bad() { echo "  FAIL $1"; FAIL=$((FAIL+1)); }
hdr() { echo; echo "=================== $1 ==================="; }

api() {
    local method="$1" path="$2" payload="${3:-}"
    local curl_args=(
        --connect-timeout "$CURL_CONNECT_TIMEOUT" --max-time "$CURL_MAX_TIME"
        --retry "$CURL_RETRIES" --retry-delay "$CURL_RETRY_DELAY" --retry-all-errors
        -fsS -X "$method" "$API$path"
    )
    if [ -n "$payload" ]; then
        curl "${curl_args[@]}" -H 'Content-Type: application/json' -d "$payload"
    else
        curl "${curl_args[@]}"
    fi
}

json_total()     { php -r '$j=json_decode(stream_get_contents(STDIN), true); echo (int)($j["total"] ?? ($j["meta"]["total"] ?? 0));'; }
json_last_page() { php -r '$j=json_decode(stream_get_contents(STDIN), true); echo (int)($j["last_page"] ?? ($j["meta"]["last_page"] ?? 1));'; }
json_ids()       { php -r '$j=json_decode(stream_get_contents(STDIN), true); foreach (($j["data"] ?? []) as $r) { echo $r["id"], "\n"; }'; }

job_query()  { api GET "/jobs?name=$RUN_ID&per_page=200&page=${1:-1}${2:-}"; }
job_total()  { job_query 1 | json_total; }
state_total(){ job_query 1 "&state=$1" | json_total; }

live_jobs() {
    local p q r ru o
    p="$(state_total pending)";  q="$(state_total queued)";   r="$(state_total retrying)"
    ru="$(state_total running)"; o="$(state_total orphaned)"
    echo $(( ${p:-0} + ${q:-0} + ${r:-0} + ${ru:-0} + ${o:-0} ))
}

# ACTIVE = live work that is still moving (pending/queued/retrying/running), i.e.
# live jobs MINUS parked orphans. The drain is "done" when active hits 0: all
# idempotent work has reached a terminal state and the only survivors (if any) are
# non-idempotent jobs deliberately parked in `orphaned` for an operator.
active_jobs() {
    local p q r ru
    p="$(state_total pending)";  q="$(state_total queued)"
    r="$(state_total retrying)"; ru="$(state_total running)"
    echo $(( ${p:-0} + ${q:-0} + ${r:-0} + ${ru:-0} ))
}

dispatch_one() {
    # mode attempts priority idempotent param_tail
    local payload
    payload="{\"job_class\":\"$JOB_CLASS\",\"name\":\"$RUN_ID\",\"params\":{\"mode\":\"$1\",\"chaos_run_id\":\"$RUN_ID\"${5:-}},\"max_attempts\":$2,\"priority\":$3,\"idempotent\":$4}"
    api POST /jobs "$payload" >/dev/null
}

dispatch_many() {
    local mode="$1" count="$2" attempts="$3" priority="$4" idem="$5" tail="${6:-}"
    local total=0 launched=0 pids="" pid
    [ "$count" -le 0 ] && return 0
    while [ "$total" -lt "$count" ]; do
        ( dispatch_one "$mode" "$attempts" "$priority" "$idem" "$tail" || exit 1 ) &
        pids="$pids $!"; launched=$((launched+1)); total=$((total+1))
        if [ "$launched" -ge "$CURL_CONCURRENCY" ]; then
            for pid in $pids; do wait "$pid" || DISPATCH_FAILURES=$((DISPATCH_FAILURES+1)); done
            pids=""; launched=0
            printf "  dispatched %-9s %7s/%-7s\r" "$mode" "$total" "$count"
        fi
    done
    for pid in $pids; do wait "$pid" || DISPATCH_FAILURES=$((DISPATCH_FAILURES+1)); done
    printf "  dispatched %-9s %7s/%-7s\n" "$mode" "$total" "$count"
}

# INTERLEAVED dispatch: every cohort is woven together so the fleet shows a live
# MIX from the first second — successes (with a real runtime), log storms,
# artifacts, stderr, throws and crashes all in flight at once — instead of a long
# block of one type. A tiny PHP fair-share pass emits the most-"behind" mode at
# each step to build the interleaved plan; bash then fires it in the same
# concurrency batches as dispatch_many, tracking per-type counts for the live line.
dispatch_mixed() {
    local plan; plan="$(mktemp)"
    local spec="{\"success\":{\"n\":$SUCCESS_JOBS,\"att\":$SUCCEED_MAX_ATTEMPTS,\"pri\":50},\"logstorm\":{\"n\":$LOGSTORM_JOBS,\"att\":$SUCCEED_MAX_ATTEMPTS,\"pri\":40},\"artifact\":{\"n\":$ARTIFACT_JOBS,\"att\":$SUCCEED_MAX_ATTEMPTS,\"pri\":40},\"stderr\":{\"n\":$STDERR_JOBS,\"att\":$SUCCEED_MAX_ATTEMPTS,\"pri\":30},\"throw\":{\"n\":$THROW_JOBS,\"att\":$FAIL_MAX_ATTEMPTS,\"pri\":30},\"crash\":{\"n\":$CRASH_JOBS,\"att\":$FAIL_MAX_ATTEMPTS,\"pri\":20}}"
    php -r '
        $spec = json_decode($argv[1], true);
        $done = []; $t = 0;
        foreach ($spec as $m => $s) { $done[$m] = 0; $t += (int) $s["n"]; }
        $f = fopen($argv[2], "w");
        for ($i = 0; $i < $t; $i++) {
            $best = null; $bs = 2.0;
            foreach ($spec as $m => $s) {
                if ((int) $s["n"] <= 0 || $done[$m] >= (int) $s["n"]) continue;
                $r = $done[$m] / (int) $s["n"];
                if ($r < $bs) { $bs = $r; $best = $m; }
            }
            $s = $spec[$best];
            fwrite($f, $best."\t".$s["att"]."\t".$s["pri"]."\n");
            $done[$best]++;
        }
        fclose($f);
    ' "$spec" "$plan"

    local total=0 launched=0 pids="" pid mode att pri tail
    local d_s=0 d_l=0 d_a=0 d_e=0 d_t=0 d_c=0
    while IFS="$(printf '\t')" read -r mode att pri; do
        [ -z "$mode" ] && continue
        case "$mode" in
            success)  tail=", \"sleep_ms\":$SUCCESS_SLEEP_MS"; d_s=$((d_s+1)) ;;
            logstorm) tail=', "lines":10';                     d_l=$((d_l+1)) ;;
            artifact) tail=', "payload_bytes":256';            d_a=$((d_a+1)) ;;
            stderr)   tail=', "lines":6';                      d_e=$((d_e+1)) ;;
            throw)    tail='';                                 d_t=$((d_t+1)) ;;
            crash)    tail='';                                 d_c=$((d_c+1)) ;;
        esac
        ( dispatch_one "$mode" "$att" "$pri" true "$tail" || exit 1 ) &
        pids="$pids $!"; launched=$((launched+1)); total=$((total+1))
        if [ "$launched" -ge "$CURL_CONCURRENCY" ]; then
            for pid in $pids; do wait "$pid" || DISPATCH_FAILURES=$((DISPATCH_FAILURES+1)); done
            pids=""; launched=0
            printf "  mixed %6s/%-6s  succ=%s log=%s art=%s err=%s throw=%s crash=%s\r" \
                "$total" "$TARGET_JOBS" "$d_s" "$d_l" "$d_a" "$d_e" "$d_t" "$d_c"
        fi
    done < "$plan"
    for pid in $pids; do wait "$pid" || DISPATCH_FAILURES=$((DISPATCH_FAILURES+1)); done
    rm -f "$plan"
    printf "  mixed %6s/%-6s  succ=%s log=%s art=%s err=%s throw=%s crash=%s\n" \
        "$total" "$TARGET_JOBS" "$d_s" "$d_l" "$d_a" "$d_e" "$d_t" "$d_c"
}

# BULK interleaved dispatch: PHP fair-interleaves every cohort, chunks the plan
# into BULK_SIZE-job bodies, and writes one JSON `{"jobs":[...]}` payload per line;
# bash then POSTs those bodies concurrently. One bootstrap per body instead of per
# job — this is what makes the fleet the bottleneck (busy, pinned) instead of the
# dispatcher. The mix is preserved: each body is a proportional slice of all types.
dispatch_bulk() {
    local plan gen
    plan="$(mktemp)"; gen="$(mktemp)"
    cat > "$gen" <<'PHPGEN'
<?php
// argv: specJson jobClass runId bulkSize successSleepMs outFile
$spec = json_decode($argv[1], true);
$jobClass = $argv[2]; $runId = $argv[3];
$bulk = max(1, (int) $argv[4]); $sleepMs = (int) $argv[5]; $out = $argv[6];

$done = []; $t = 0;
foreach ($spec as $m => $s) { $done[$m] = 0; $t += (int) $s['n']; }
$seq = [];
for ($i = 0; $i < $t; $i++) {                 // fair-interleave the modes
    $best = null; $bestScore = 2.0;
    foreach ($spec as $m => $s) {
        if ((int) $s['n'] <= 0 || $done[$m] >= (int) $s['n']) continue;
        $r = $done[$m] / (int) $s['n'];
        if ($r < $bestScore) { $bestScore = $r; $best = $m; }
    }
    $seq[] = $best; $done[$best]++;
}
$paramsFor = static function (string $mode) use ($sleepMs) {
    switch ($mode) {
        case 'success':  return ['sleep_ms' => $sleepMs];
        case 'logstorm': return ['lines' => 10];
        case 'artifact': return ['payload_bytes' => 256];
        case 'stderr':   return ['lines' => 6];
        default:         return [];
    }
};
$f = fopen($out, 'w');
$chunk = [];
$flush = static function () use (&$chunk, $f) {
    if ($chunk) { fwrite($f, json_encode(['jobs' => $chunk]) . "\n"); $chunk = []; }
};
foreach ($seq as $key) {                      // $key is the cohort; $s['mode'] the job mode
    $s = $spec[$key];
    $mode = $s['mode'];
    $chunk[] = [
        'job_class' => $jobClass,
        'name' => $runId,
        'params' => array_merge(['mode' => $mode, 'jobwarden_run_id' => $runId], $paramsFor($mode)),
        'idempotent' => (bool) $s['idem'],
        'max_attempts' => (int) $s['att'],
        'priority' => (int) $s['pri'],
    ];
    if (count($chunk) >= $bulk) { $flush(); }
}
$flush();
fclose($f);
PHPGEN
    php "$gen" \
        "{\"success\":{\"n\":$SUCCESS_JOBS,\"att\":$SUCCEED_MAX_ATTEMPTS,\"pri\":50,\"mode\":\"success\",\"idem\":true},\"nonidem\":{\"n\":$NONIDEM_JOBS,\"att\":1,\"pri\":50,\"mode\":\"success\",\"idem\":false},\"logstorm\":{\"n\":$LOGSTORM_JOBS,\"att\":$SUCCEED_MAX_ATTEMPTS,\"pri\":40,\"mode\":\"logstorm\",\"idem\":true},\"artifact\":{\"n\":$ARTIFACT_JOBS,\"att\":$SUCCEED_MAX_ATTEMPTS,\"pri\":40,\"mode\":\"artifact\",\"idem\":true},\"stderr\":{\"n\":$STDERR_JOBS,\"att\":$SUCCEED_MAX_ATTEMPTS,\"pri\":30,\"mode\":\"stderr\",\"idem\":true},\"throw\":{\"n\":$THROW_JOBS,\"att\":$FAIL_MAX_ATTEMPTS,\"pri\":30,\"mode\":\"throw\",\"idem\":true},\"crash\":{\"n\":$CRASH_JOBS,\"att\":$FAIL_MAX_ATTEMPTS,\"pri\":20,\"mode\":\"crash\",\"idem\":true}}" \
        "$JOB_CLASS_PLAIN" "$RUN_ID" "$BULK_SIZE" "$SUCCESS_SLEEP_MS" "$plan"
    rm -f "$gen"

    local bodies posted=0 launched=0 pids="" pid body
    bodies="$(wc -l < "$plan" | tr -d ' ')"
    while IFS= read -r body; do
        ( printf '%s' "$body" | curl -fsS --connect-timeout "$CURL_CONNECT_TIMEOUT" --max-time 120 \
            --retry "$CURL_RETRIES" --retry-delay "$CURL_RETRY_DELAY" --retry-all-errors \
            -X POST "$API/jobs" -H 'Content-Type: application/json' --data-binary @- >/dev/null || exit 1 ) &
        pids="$pids $!"; launched=$((launched+1)); posted=$((posted+1))
        if [ "$launched" -ge "$BULK_CONCURRENCY" ]; then
            for pid in $pids; do wait "$pid" || DISPATCH_FAILURES=$((DISPATCH_FAILURES+1)); done
            pids=""; launched=0
            printf "  bulk %5s/%-5s bodies (%s jobs/body)\r" "$posted" "$bodies" "$BULK_SIZE"
        fi
    done < "$plan"
    for pid in $pids; do wait "$pid" || DISPATCH_FAILURES=$((DISPATCH_FAILURES+1)); done
    rm -f "$plan"
    printf "  bulk %5s/%-5s bodies posted (%s jobs/body)\n" "$posted" "$bodies" "$BULK_SIZE"
}

trigger_restart() {
    [ "$RESTART_MODE" = "none" ] && { RESTARTED=1; return 0; }
    local scope=""
    [ "$RESTART_MODE" = "hosts" ] && scope="host"
    hdr "RESTART CHAOS ($RESTART_MODE)"
    echo "  live-at-restart=$(live_jobs) succeeded=$(state_total succeeded) failed=$(state_total failed)"
    echo "  running: $RESTART_CMD restart $scope"
    local t0=$SECONDS
    # shellcheck disable=SC2086
    $RESTART_CMD restart $scope >/dev/null 2>&1 \
        && echo "  restart command returned after $((SECONDS-t0))s" \
        || bad "restart command failed"
    RESTARTED=1
    # Give the fleet a beat to re-register before we resume polling assertions.
    sleep 5
}

print_progress() {
    local live succ fail orph retr run
    live="$(live_jobs)"; succ="$(state_total succeeded)"; fail="$(state_total failed)"
    orph="$(state_total orphaned)"; retr="$(state_total retrying)"; run="$(state_total running)"
    [ "${orph:-0}" -gt "$PEAK_ORPHANED" ] && PEAK_ORPHANED="$orph"
    [ "${retr:-0}" -gt "$PEAK_RETRYING" ] && PEAK_RETRYING="$retr"
    [ "${live:-0}" -gt "$PEAK_LIVE" ] && PEAK_LIVE="$live"
    printf "  jobs=%7s/%-7s live=%7s run=%6s orph=%6s retr=%6s succ=%7s fail=%7s restarted=%s\r" \
        "$(job_total)" "$TARGET_JOBS" "$live" "$run" "$orph" "$retr" "$succ" "$fail" "$RESTARTED"
}

# ---- sampled deep inspection (bounded HTTP; the aggregate ledger is the proof) ----
deep_sample() {
    hdr "DEEP SAMPLE INSPECTION (n<=$SAMPLE_SIZE)"
    local last ids inspected=0 terminal=0 with_attempts=0 bad_state=0 retried=0
    last="$(job_query 1 | json_last_page)"
    # Spread the sample across the whole population by hitting scattered pages.
    local pages="" step page
    step=$(( last / (SAMPLE_SIZE/50 + 1) )); [ "$step" -lt 1 ] && step=1
    page=1
    while [ "$page" -le "$last" ] && [ "$inspected" -lt "$SAMPLE_SIZE" ]; do
        ids="$(job_query "$page" | json_ids | head -50)"
        while IFS= read -r id; do
            id="$(echo "$id" | tr -d '[:space:]')"; [ -z "$id" ] && continue
            [ "$inspected" -ge "$SAMPLE_SIZE" ] && break
            eval "$( JOB_JSON="$(api GET "/jobs/$id")" php <<'PHP'
<?php
$j = json_decode(getenv('JOB_JSON'), true);
$state = $j['state'] ?? '';
$terminal = in_array($state, ['succeeded','failed','canceled','stopped'], true) ? 1 : 0;
$attempts = count($j['attempts'] ?? []);
$hasAtt = $attempts > 0 ? 1 : 0;
$retried = $attempts > 1 ? 1 : 0;
$badState = ($terminal && $hasAtt) ? 0 : 1;
echo "s_terminal=$terminal\ns_hasatt=$hasAtt\ns_retried=$retried\ns_bad=$badState\n";
PHP
            )"
            terminal=$((terminal + s_terminal)); with_attempts=$((with_attempts + s_hasatt))
            retried=$((retried + s_retried)); bad_state=$((bad_state + s_bad))
            inspected=$((inspected+1))
            [ $((inspected % 50)) -eq 0 ] && printf "  inspected=%4s terminal=%4s retried=%4s\r" "$inspected" "$terminal" "$retried"
        done <<< "$ids"
        page=$((page + step))
    done
    echo
    echo "  inspected=$inspected terminal=$terminal with_attempts=$with_attempts retried(attempts>1)=$retried inconsistent=$bad_state"
    [ "$inspected" -gt 0 ] && [ "$terminal" = "$inspected" ] && ok "every sampled job is in a terminal state" || bad "non-terminal sampled jobs: $((inspected-terminal))"
    [ "$bad_state" = "0" ] && ok "every sampled terminal job has >=1 attempt (parent/attempt consistent)" || bad "$bad_state sampled jobs inconsistent"
    if [ "$RESTARTED" = "1" ] && [ "$RESTART_MODE" != "none" ]; then
        [ "$retried" -gt "0" ] && ok "recovery evidence: sampled jobs with attempt_count>1 present ($retried)" || echo "  NOTE: no multi-attempt jobs in sample (restart may have missed this slice)"
    fi
}

# =====================================================================
hdr "CHECK API + FLEET"
echo "  api=$API"
echo "  run_id=$RUN_ID  scale=$SCALE"
echo "  target=$TARGET_JOBS  idempotent-succeed=$EXPECTED_IDEMPOTENT_SUCCEEDED  fail=$EXPECTED_FAILED  non-idempotent=$NONIDEM_JOBS (${NONIDEMPOTENT_PCT}% of success)"
echo "  cohorts: success=$SUCCESS_JOBS nonidem=$NONIDEM_JOBS logstorm=$LOGSTORM_JOBS artifact=$ARTIFACT_JOBS stderr=$STDERR_JOBS throw=$THROW_JOBS crash=$CRASH_JOBS"
echo "  restart_mode=$RESTART_MODE  restart_at=$RESTART_AT terminal jobs  curl_concurrency=$CURL_CONCURRENCY"
api GET /stats >/dev/null || { bad "API not reachable at $API"; exit 1; }
ok "operator API is reachable"

supervisors="$(api GET '/stats' | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo (int)($j["workers"]["supervisor"] ?? 0);')"
echo "  active supervisor workers=$supervisors"
[ "${supervisors:-0}" -ge 8 ] && ok "a real multi-host supervisor fleet is active ($supervisors)" || bad "only $supervisors supervisor worker(s) active"

hdr "DISPATCH $TARGET_JOBS JOBS — INTERLEAVED MIX, BULK"
echo "  types woven together: success(${SUCCESS_SLEEP_MS}ms) + nonidem + logstorm + artifact + stderr + throw + crash"
echo "  bulk: $BULK_SIZE jobs/body, $BULK_CONCURRENCY bodies in flight"
existing="$(job_total)"
if [ "$existing" = "0" ]; then
    dispatch_bulk
elif [ "$existing" = "$TARGET_JOBS" ]; then
    echo "  found existing run with $existing jobs; resuming drain/inspection"
else
    bad "run_id already has $existing jobs, expected 0 or $TARGET_JOBS"; exit 1
fi

[ "$DISPATCH_FAILURES" = "0" ] && ok "all curl dispatch requests completed" || bad "$DISPATCH_FAILURES curl dispatch request(s) failed"
jobs="$(job_total)"
[ "$jobs" = "$TARGET_JOBS" ] && ok "exact job count queued through individual curl requests ($jobs)" || bad "queued $jobs jobs, expected $TARGET_JOBS"

hdr "DRAIN + RESTART CHAOS VIA CURL"
deadline=$((SECONDS+DRAIN_SAFETY_SECONDS))
settled=0
while [ $SECONDS -lt $deadline ]; do
    print_progress
    if [ "$RESTARTED" = "0" ] && [ "$(state_total succeeded)" -ge "$RESTART_AT" ]; then
        echo
        trigger_restart
    fi
    # Done when all live work has drained and only parked orphans (if any) remain.
    # Require two consecutive zero reads so a transient dip while an idempotent
    # orphan is mid-resolution (orphaned → retrying) doesn't end the drain early.
    if [ "$(active_jobs)" = "0" ] && [ "$RESTARTED" = "1" ]; then
        settled=$((settled+1))
        [ "$settled" -ge 2 ] && break
    else
        settled=0
    fi
    sleep 3
done
echo

hdr "ASSERT CURL-OBSERVED FIDELITY LEDGER"
live="$(live_jobs)"
total="$(job_total)"
succeeded="$(state_total succeeded)"
failed="$(state_total failed)"
stopped="$(state_total stopped)"
canceled="$(state_total canceled)"
orphaned="$(state_total orphaned)"

echo "  total=$total live=$live succeeded=$succeeded failed=$failed stopped=$stopped canceled=$canceled orphaned=$orphaned"
echo "  peak-during-drain: live=$PEAK_LIVE orphaned=$PEAK_ORPHANED retrying=$PEAK_RETRYING  restarted=$RESTARTED"

active="$(active_jobs)"

[ "$total" = "$TARGET_JOBS" ] \
    && ok "no job lost or duplicated: exactly $TARGET_JOBS jobs visible" \
    || bad "total=$total expected $TARGET_JOBS"

# Whatever the mix, NOTHING may be stuck mid-flight — no job left running/queued/
# retrying/pending. All idempotent work must have converged to a terminal state.
[ "${active:-1}" = "0" ] \
    && ok "no job stuck in running/queued/retrying limbo — all live work converged" \
    || bad "$active jobs still active (neither terminal nor parked)"

# The throw/crash cohort is deterministic regardless of the restart.
[ "$failed" = "$EXPECTED_FAILED" ] \
    && ok "$EXPECTED_FAILED throw/crash jobs deterministically failed" \
    || bad "failed=$failed expected $EXPECTED_FAILED"

if [ "${NONIDEM_JOBS:-0}" -gt 0 ]; then
    # ---- BOTH halves of the idempotency guard ----
    # (1) idempotent successes always converge; non-idempotent ones the restart
    #     missed also succeed → `succeeded` lands in a known RANGE.
    { [ "$succeeded" -ge "$EXPECTED_IDEMPOTENT_SUCCEEDED" ] && [ "$succeeded" -le "$EXPECTED_SUCCEEDED_MAX" ]; } \
        && ok "succeeded in expected range [$EXPECTED_IDEMPOTENT_SUCCEEDED..$EXPECTED_SUCCEEDED_MAX] (got $succeeded)" \
        || bad "succeeded=$succeeded outside [$EXPECTED_IDEMPOTENT_SUCCEEDED..$EXPECTED_SUCCEEDED_MAX]"
    # (2) every survivor is a PARKED orphan (active==0 ⇒ live==orphaned).
    [ "$live" = "$orphaned" ] \
        && ok "every survivor is a PARKED orphan, not stuck work (live=$live, all orphaned)" \
        || bad "live=$live but orphaned=$orphaned — some survivors are not parked orphans"
    # (3) full accounting, parked orphans included.
    [ $((succeeded + failed + orphaned)) = "$TARGET_JOBS" ] \
        && ok "ledger balances with parked orphans: succeeded+failed+orphaned = target" \
        || bad "succeeded+failed+orphaned=$((succeeded + failed + orphaned)) != $TARGET_JOBS"
    # (4) parked for the RIGHT reason: sampled orphans are all non-idempotent.
    if [ "${orphaned:-0}" -gt 0 ]; then
        parked="$(api GET "/jobs?name=$RUN_ID&state=orphaned&per_page=200" | php -r '$j=json_decode(stream_get_contents(STDIN),true);$t=0;$ni=0;foreach(($j["data"]??[]) as $r){$t++; if(empty($r["idempotent"]))$ni++;} echo "$ni $t";')"
        p_ni="${parked%% *}"; p_tot="${parked##* }"
        { [ "${p_tot:-0}" -gt 0 ] && [ "${p_ni:-0}" = "${p_tot:-1}" ]; } \
            && ok "all sampled parked orphans are NON-idempotent ($p_ni/$p_tot) — never auto-retried" \
            || bad "some sampled parked orphans are idempotent ($p_ni/$p_tot) — the guard leaked"
    fi
    echo "  NON-IDEMPOTENT GUARD: $orphaned of $NONIDEM_JOBS non-idempotent jobs were caught in-flight by the restart and correctly PARKED for an operator (never auto-retried, never lost)."
else
    # ---- strict all-idempotent mode: everything reaches a terminal state ----
    [ "${live:-1}" = "0" ] \
        && ok "NO JOB LEFT BEHIND: every job drained to a terminal state" \
        || bad "$live jobs still live/limbo (orphaned=$orphaned)"
    [ "$succeeded" = "$EXPECTED_IDEMPOTENT_SUCCEEDED" ] \
        && ok "$EXPECTED_IDEMPOTENT_SUCCEEDED idempotent jobs succeeded" \
        || bad "succeeded=$succeeded expected $EXPECTED_IDEMPOTENT_SUCCEEDED"
    [ $((succeeded + failed)) = "$TARGET_JOBS" ] \
        && ok "terminal ledger balances (succeeded+failed = target)" \
        || bad "succeeded+failed=$((succeeded + failed)) != $TARGET_JOBS"
fi

if [ "$RESTART_MODE" != "none" ]; then
    [ "$PEAK_ORPHANED" -gt "0" ] && ok "restart was real: attempts were orphaned then recovered (peak orphaned=$PEAK_ORPHANED)" \
        || echo "  NOTE: peak orphaned=0 — restart may have landed between reaper scans; ledger fidelity still holds"
fi

deep_sample

hdr "RESULT"
echo "  PASS=$PASS  FAIL=$FAIL  (restart_mode=$RESTART_MODE)"
[ "$FAIL" = "0" ] && { echo "  GIANT CURL API CHAOS VERIFIED — 100% fidelity across restart"; exit 0; } || { echo "  FAILURES"; exit 1; }
