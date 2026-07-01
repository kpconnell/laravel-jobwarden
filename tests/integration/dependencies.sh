#!/usr/bin/env bash
#
# Dependency-semantics acceptance test — REAL supervisors (spec §8.1, strict deps).
#
#   diamond  : A → {B,C} → D. Proves, with real timestamps:
#              B/C never start before A finishes (deps), B/C run CONCURRENTLY
#              (fan-out), and D starts only after BOTH B and C finish (fan-in).
#   failchain : continue; root A fails → B and C are unreachable and cascade-cancel
#               → batch partial (does NOT hang).
#   failfanin : continue; D joins A+B, B fails → D unreachable even though A
#               succeeds → batch partial.
#
# Run from the repo root on the docker host:  bash tests/integration/dependencies.sh
set -uo pipefail

TB="vendor/bin/testbench"
PASS=0; FAIL=0
ok()  { echo "  ✅ $1"; PASS=$((PASS+1)); }
bad() { echo "  ❌ $1"; FAIL=$((FAIL+1)); }

q() { docker compose exec -T pg psql -U jobwarden -d jobwarden -tAc "$1" 2>/dev/null | tr -d '[:space:]'; }
mstate() { q "select state from jobwarden_jobs where batch_id='$1' and name='$2'"; }
reset() { docker compose restart host1 >/dev/null 2>&1; sleep 2; docker compose exec -T host1 $TB migrate:fresh --database=jobwarden >/dev/null 2>&1; }

# ------------------------------------------------- diamond DAG ----------------
echo "=================== diamond DAG: A → {B,C} → D ==================="
docker compose up -d pg host1 >/dev/null 2>&1
reset
DIA=$(docker compose exec -T host1 $TB jobwarden:demo:batch diamond --sleep=3 2>&1 | grep -oE '[0-9a-f]{8}-[0-9a-f-]+' | head -1)
echo "  dispatched diamond batch: $DIA"
[ "$(q "select count(*) from jobwarden_jobs where batch_id='$DIA' and state='queued'")" = "1" ] && \
[ "$(q "select count(*) from jobwarden_jobs where batch_id='$DIA' and state='pending'")" = "3" ] && \
    ok "at dispatch: only A eligible, B/C/D gated" || bad "initial eligibility wrong"

docker compose exec -d host1 $TB jobwarden:work --capacity=4 >/dev/null 2>&1
max_concurrent=0
deadline=$((SECONDS+60))
while [ $SECONDS -lt $deadline ]; do
    r=$(q "select count(*) from jobwarden_jobs where batch_id='$DIA' and state='running'")
    [ "${r:-0}" -gt "$max_concurrent" ] && max_concurrent=$r
    [ "$(q "select state from jobwarden_batches where id='$DIA'")" = "succeeded" ] && break
    sleep 1
done

bstate=$(q "select state from jobwarden_batches where id='$DIA'")
echo "  batch.state=$bstate  max_concurrent_observed=$max_concurrent"
[ "$bstate" = "succeeded" ] && ok "diamond batch succeeded" || bad "batch state=$bstate"
[ "${max_concurrent:-0}" -ge 2 ] && ok "B and C ran CONCURRENTLY (fan-out after A)" || bad "never saw 2 concurrent (fan-out failed)"

# Ordering, from real timestamps:
bc_after_a=$(q "select (b.started_at >= a.finished_at and c.started_at >= a.finished_at)
    from jobwarden_jobs a, jobwarden_jobs b, jobwarden_jobs c
    where a.batch_id='$DIA' and a.name='a' and b.batch_id='$DIA' and b.name='b' and c.batch_id='$DIA' and c.name='c'")
d_after_bc=$(q "select (d.started_at >= b.finished_at and d.started_at >= c.finished_at)
    from jobwarden_jobs d, jobwarden_jobs b, jobwarden_jobs c
    where d.batch_id='$DIA' and d.name='d' and b.batch_id='$DIA' and b.name='b' and c.batch_id='$DIA' and c.name='c'")
[ "$bc_after_a" = "t" ] && ok "B and C started only AFTER A finished (deps respected)" || bad "B/C started before A finished"
[ "$d_after_bc" = "t" ] && ok "D (fan-in) started only after BOTH B and C finished" || bad "D started before its inputs finished"

# ------------------------------------------------- failchain ------------------
echo
echo "=================== failchain (continue): A fails → B,C cascade-cancel ==================="
reset
FC=$(docker compose exec -T host1 $TB jobwarden:demo:batch failchain 2>&1 | grep -oE '[0-9a-f]{8}-[0-9a-f-]+' | head -1)
echo "  dispatched failchain batch: $FC"
docker compose exec -d host1 $TB jobwarden:work --capacity=3 >/dev/null 2>&1
deadline=$((SECONDS+40))
while [ $SECONDS -lt $deadline ]; do
    case "$(q "select state from jobwarden_batches where id='$FC'")" in partial|failed|succeeded|canceled) break;; esac
    sleep 1
done
echo "  batch.state=$(q "select state from jobwarden_batches where id='$FC'")  a=$(mstate "$FC" a) b=$(mstate "$FC" b) c=$(mstate "$FC" c)"
[ "$(q "select state from jobwarden_batches where id='$FC'")" = "partial" ] && ok "batch completed partial (did NOT hang)" || bad "batch did not complete partial"
[ "$(mstate "$FC" a)" = "failed" ]   && ok "A failed"                                  || bad "A state=$(mstate "$FC" a)"
[ "$(mstate "$FC" b)" = "canceled" ] && ok "B cascade-canceled (unreachable via A)"    || bad "B state=$(mstate "$FC" b)"
[ "$(mstate "$FC" c)" = "canceled" ] && ok "C cascade-canceled (transitively via B)"   || bad "C state=$(mstate "$FC" c)"

# ------------------------------------------------- failfanin ------------------
echo
echo "=================== failfanin (continue): D joins A+B, B fails → D unreachable ==================="
reset
FI=$(docker compose exec -T host1 $TB jobwarden:demo:batch failfanin --sleep=2 2>&1 | grep -oE '[0-9a-f]{8}-[0-9a-f-]+' | head -1)
echo "  dispatched failfanin batch: $FI"
docker compose exec -d host1 $TB jobwarden:work --capacity=3 >/dev/null 2>&1
deadline=$((SECONDS+40))
while [ $SECONDS -lt $deadline ]; do
    case "$(q "select state from jobwarden_batches where id='$FI'")" in partial|failed|succeeded|canceled) break;; esac
    sleep 1
done
echo "  batch.state=$(q "select state from jobwarden_batches where id='$FI'")  a=$(mstate "$FI" a) b=$(mstate "$FI" b) d=$(mstate "$FI" d)"
[ "$(q "select state from jobwarden_batches where id='$FI'")" = "partial" ] && ok "batch completed partial" || bad "batch state wrong"
[ "$(mstate "$FI" a)" = "succeeded" ] && ok "A succeeded (unrelated branch ran)"        || bad "A state=$(mstate "$FI" a)"
[ "$(mstate "$FI" b)" = "failed" ]    && ok "B failed"                                  || bad "B state=$(mstate "$FI" b)"
[ "$(mstate "$FI" d)" = "canceled" ]  && ok "D canceled (one input failed → unreachable)" || bad "D state=$(mstate "$FI" d)"

echo
echo "=================== RESULT ==================="
echo "  PASS=$PASS  FAIL=$FAIL"
docker compose restart host1 >/dev/null 2>&1
[ "$FAIL" = "0" ] && { echo "  ✅ DEPENDENCY SEMANTICS VERIFIED"; exit 0; } || { echo "  ❌ FAILURES"; exit 1; }
