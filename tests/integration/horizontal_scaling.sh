#!/usr/bin/env bash
#
# Horizontal-scaling acceptance test — REAL multi-host, no mocks.
#
#   PART A: supervisors on host1 AND host2 drain a shared pool under load;
#           assert NO job is picked up twice and BOTH hosts participated.
#   PART B: host1 claims a long job, then host1 is KILLed (plug pulled); the
#           global reaper running on a DIFFERENT host declares host1 dead,
#           orphans its in-flight attempt, and an idempotent recovery re-runs the
#           job on host2.
#
# Run from the repo root on the docker host:  bash tests/integration/horizontal_scaling.sh
set -uo pipefail

TB="vendor/bin/testbench"
PASS=0; FAIL=0
ok()   { echo "  ✅ $1"; PASS=$((PASS+1)); }
bad()  { echo "  ❌ $1"; FAIL=$((FAIL+1)); }
hdr()  { echo; echo "=================== $1 ==================="; }

# Target engine: pgsql (default) or mysql/MariaDB. Set JOBWARDEN_E2E_DB=mysql to
# run the whole distributed proof against MariaDB instead of Postgres.
DB="${JOBWARDEN_E2E_DB:-pgsql}"
if [ "$DB" = "mysql" ]; then
    DBSVC="maria"
    DBENV="-e JOBWARDEN_DB_DRIVER=mysql -e JOBWARDEN_DB_HOST=maria -e JOBWARDEN_DB_PORT=3306"
    q() { docker compose exec -T maria mariadb -ujobwarden -psecret -N jobwarden -e "$1" 2>/dev/null | tr -d '[:space:]'; }
else
    DBSVC="pg"
    DBENV=""
    q() { docker compose exec -T pg psql -U jobwarden -d jobwarden -tAc "$1" 2>/dev/null | tr -d '[:space:]'; }
fi
echo "  (engine under test: $DB)"
migrate() { docker compose exec -T $DBENV host1 $TB migrate:fresh --database=jobwarden >/dev/null 2>&1; }
sup() { docker compose exec -d $DBENV "$1" $TB jobwarden:work --capacity="$2" >/dev/null 2>&1; }
stop_all() { docker compose restart host1 host2 globalreaper >/dev/null 2>&1; sleep 2; }

# ---------------------------------------------------------------- PART A ------
hdr "PART A — no double-pickup under load (host1 + host2)"
docker compose up -d $DBSVC host1 host2 globalreaper >/dev/null 2>&1
stop_all
migrate

N=120
docker compose exec -T $DBENV host1 $TB jobwarden:demo:dispatch marker --count=$N >/dev/null 2>&1
echo "  dispatched $N jobs; starting supervisors on host1 AND host2 ..."
sup host1 6
sup host2 6

deadline=$((SECONDS+150))
while [ $SECONDS -lt $deadline ]; do
    done=$(q "select count(*) from jobwarden_jobs where state='succeeded'")
    [ "${done:-0}" = "$N" ] && break
    sleep 2
done

succeeded=$(q "select count(*) from jobwarden_jobs where state='succeeded'")
attempts=$(q "select count(*) from jobwarden_job_attempts")
doubled=$(q "select count(*) from jobwarden_jobs where attempt_count > 1")
hosts=$(q "select count(distinct host_id) from jobwarden_job_attempts")
per_host=$(q "select host_id, count(*) from jobwarden_job_attempts group by host_id")

echo "  succeeded=$succeeded  attempts=$attempts  jobs_with_>1_attempt=$doubled  distinct_hosts=$hosts"
echo "  attempts per host:"; echo "$per_host" | sed 's/^/    /'
[ "$succeeded" = "$N" ]   && ok "all $N jobs succeeded"                || bad "only $succeeded/$N succeeded"
[ "$attempts" = "$N" ]    && ok "exactly $N attempts (one per job)"    || bad "expected $N attempts, got $attempts"
[ "$doubled" = "0" ]      && ok "NO job was picked up twice"           || bad "$doubled jobs ran more than once"
[ "${hosts:-0}" -ge 2 ]   && ok "BOTH hosts participated"              || bad "only $hosts host(s) claimed work"

# ---------------------------------------------------------------- PART B ------
hdr "PART B — a lost host's running job is orphaned by another host"
stop_all
migrate

# Tight host-down budget for the reaper (heartbeat_interval*missed_beats = 3s).
REAPER_ENV="-e JOBWARDEN_HEARTBEAT_INTERVAL=1 -e JOBWARDEN_MISSED_BEATS=3 -e JOBWARDEN_BACKOFF_STRATEGY=fixed -e JOBWARDEN_BACKOFF_BASE=1"

JOB=$(docker compose exec -T $DBENV host1 $TB jobwarden:demo:dispatch marker --sleep=10 --max-attempts=3 2>&1 | grep -oE '[0-9a-f]{8}-[0-9a-f-]+')
echo "  dispatched long idempotent job: $JOB"
sup host1 2

# Wait until host1 is actually RUNNING it.
deadline=$((SECONDS+30))
while [ $SECONDS -lt $deadline ]; do
    st=$(q "select state from jobwarden_jobs where id='$JOB'")
    [ "$st" = "running" ] && break
    sleep 1
done
HOST1=$(q "select a.host_id from jobwarden_job_attempts a where a.job_id='$JOB' order by a.attempt_number desc limit 1")
A1=$(q "select a.id from jobwarden_job_attempts a where a.job_id='$JOB' order by a.attempt_number desc limit 1")
echo "  job is running on host_id=${HOST1:0:12}…  attempt=$A1"
[ "$(q "select state from jobwarden_jobs where id='$JOB'")" = "running" ] && ok "host1 is running the job" || bad "job never reached running on host1"

echo "  >>> KILL host1 (plug pulled, no drain) <<<"
docker compose kill host1 >/dev/null 2>&1

echo "  waiting for host1's lease to go stale, then running the global reaper on the globalreaper host ..."
sleep 5
for i in 1 2 3; do
    docker compose exec -T $DBENV $REAPER_ENV globalreaper $TB jobwarden:reap:global --once 2>&1 | sed 's/^/    reaper: /'
    sleep 2
done

orphaned_state=$(q "select state from jobwarden_job_attempts where id='$A1'")
fence=$(q "select fencing_token from jobwarden_job_attempts where id='$A1'")
job_state=$(q "select state from jobwarden_jobs where id='$JOB'")
reaplog=$(q "select count(*) from jobwarden_job_logs where attempt_id='$A1' and step='reaped'")
worker_dead=$(q "select state from jobwarden_workers where host_id='$HOST1' and role='supervisor' order by started_at desc limit 1")

echo "  after reap: attempt.state=$orphaned_state fence=$fence job.state=$job_state reaplog=$reaplog host1.worker=$worker_dead"
[ "$orphaned_state" = "orphaned" ] && ok "host1's attempt was orphaned by the reaper on another host" || bad "attempt state is '$orphaned_state' (expected orphaned)"
[ "${fence:-0}" -ge 2 ]           && ok "fencing token bumped ($fence) — old host fenced out"          || bad "fence not bumped ($fence)"
[ "$worker_dead" = "dead" ]       && ok "host1 declared dead in the registry"                          || bad "host1 worker state is '$worker_dead'"
[ "${reaplog:-0}" -ge 1 ]         && ok "reaper recorded its action in the job log"                    || bad "no reaper entry in job_logs"
case "$job_state" in retrying|queued|running|succeeded) ok "idempotent job recovered (state=$job_state)";; *) bad "job did not recover (state=$job_state)";; esac

echo "  bringing host2 in to absorb the recovered job ..."
sup host2 2
deadline=$((SECONDS+40))
while [ $SECONDS -lt $deadline ]; do
    st=$(q "select state from jobwarden_jobs where id='$JOB'")
    { [ "$st" = "succeeded" ] || [ "$st" = "failed" ]; } && break
    sleep 2
done
final_state=$(q "select state from jobwarden_jobs where id='$JOB'")
attempts2=$(q "select count(*) from jobwarden_job_attempts where job_id='$JOB'")
HOST2=$(q "select a.host_id from jobwarden_job_attempts a where a.job_id='$JOB' order by a.attempt_number desc limit 1")
echo "  final: job.state=$final_state attempts=$attempts2 final_attempt_host=${HOST2:0:12}…"
[ "$final_state" = "succeeded" ]  && ok "job ultimately succeeded after host loss"           || bad "job final state is '$final_state'"
[ "${attempts2:-0}" -ge 2 ]       && ok "a fresh attempt was minted (attempts=$attempts2)"    || bad "no new attempt minted"
[ -n "$HOST2" ] && [ "$HOST2" != "$HOST1" ] && ok "recovered on a DIFFERENT host (host2)"      || bad "did not recover on a different host"

# ---------------------------------------------------------------- result -----
hdr "RESULT"
echo "  PASS=$PASS  FAIL=$FAIL"
docker compose restart host1 >/dev/null 2>&1   # bring host1 back for further use
[ "$FAIL" = "0" ] && { echo "  ✅ HORIZONTAL SCALING VERIFIED"; exit 0; } || { echo "  ❌ FAILURES"; exit 1; }
