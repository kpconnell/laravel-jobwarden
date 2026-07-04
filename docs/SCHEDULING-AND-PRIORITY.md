# Scheduling & Priority

How JobWarden decides **which job runs next**: lanes, the priority column, the
claim ordering, admission of delayed/retrying work, and what deliberately does
*not* exist (OS process priorities, priority aging, cross-lane ordering).

The one design fact everything below follows from: **scheduling policy lives in
exactly two ordered queries** — the claim (which `queued` job a free worker
slot takes) and the admit pass (which eligible `pending`/`retrying` jobs are
promoted into `queued`). Both order `priority DESC` first. Everything else —
`available_at`, dependencies, backoff, lanes — is an **eligibility gate or a
partition**, never an ordering mechanism.

## The pipeline

```
dispatch ──────────────► queued ──claim──► running
   │                        ▲
   ├─ delay/available_at    │ admit pass (Admitter, every supervisor tick)
   ├─ dependencies ───► pending
   └─ retry backoff ──► retrying
```

- An undelayed dispatch is born directly in `queued` — it never touches the
  admit pass.
- A delayed dispatch or a dependency-gated batch member is born `pending`; a
  failed idempotent attempt goes to `retrying` with a backoff. Both are
  promoted to `queued` by the admit pass once eligible.
- Scheduled jobs are born `queued` even when their occurrence is in the
  future — the claim's own `available_at <= CURRENT_TIMESTAMP` gate holds them
  until due, so they bypass admission too.

All eligibility math runs on the **DB clock** (`CURRENT_TIMESTAMP` /
`SqlTime`), never the app clock — dispatch delay, admission, and claim compare
in the database's frame regardless of `app.timezone`.

## Priority: the one explicit mechanism

`jobs.priority` is a `SMALLINT`, default `0`, **higher wins**. It is set at
creation and never mutated:

```php
SendInvoice::priority(10)->dispatch($invoiceId);            // fluent dispatch
JobWarden::dispatch(SendInvoice::class, $params, ['priority' => 10]);
JobWarden::schedule('nightly', '0 2 * * *', Rollup::class, [], ['priority' => 5]);
$batch->add('root', ImportChunk::class, options: ['priority' => 3]);
```

A schedule's priority is copied verbatim onto every job it materializes.

Semantics:

- **Strict, not weighted.** Within a lane, an eligible priority-10 job is
  always claimed before any priority-0 job. There is no lottery, no share.
- **No aging or escalation.** A job's priority never changes. A saturated
  stream of high-priority work will starve lower priorities in the same lane
  indefinitely — by design. If two classes of work must both make progress
  under sustained load, **separate them into lanes** (that is what lanes are
  for), don't interleave priorities.
- **Non-preemptive.** Priority decides which job takes a free slot; it never
  interrupts a running child.

## Claim ordering: `priority DESC, created_at ASC`

Both claim drivers (`skip_locked` and the `optimistic` fallback) issue the
same selection:

```sql
WHERE lane = :worker_lane
  AND state = 'queued'
  AND (available_at IS NULL OR available_at <= CURRENT_TIMESTAMP)
ORDER BY priority DESC, created_at ASC
LIMIT :free_slots
```

The in-band tiebreak is **`created_at`, deliberately not `available_at`**:

- `created_at` is stamped once at dispatch and **survives retries and
  requeues**. When a retry's backoff elapses (or an operator restarts an
  orphan), the job's original age places it *ahead of fresher work* in its
  priority band — it does not go to the back of the line.
- `available_at` is only ever the eligibility gate in the `WHERE`. Sorting by
  it would demote every retry to the tail of the lane.

## Admission: `priority DESC, available_at ASC`

The admit pass (`Admitter`, run by every supervisor at the top of every tick)
promotes `retrying → queued` (backoff elapsed) then `pending → queued` (due
and dependency-satisfied), each in a window of up to 200 rows per pass:

```sql
WHERE state = :from
  AND (available_at IS NULL OR available_at <= CURRENT_TIMESTAMP)
ORDER BY priority DESC, available_at ASC
LIMIT 200
```

Why priority leads here too: below the window size, admission order is
invisible — the claim re-sorts `queued` anyway. But fleets routinely have more
eligible rows than one window, and ordered by due time alone every slot would
go to earlier-due low-priority rows while a high-priority job waited passes on
end, already *running* low-priority work in the meantime. Priority-first makes
the window consistent with the claim: **priority is never left behind because
of `available_at`**.

Within a band the tiebreak is due time (earliest first). Two more admission
facts worth knowing:

- The pass is **lane-global** — any supervisor promotes eligible work for all
  lanes, so a lane's jobs are admitted even while its own workers are down
  (they simply wait in `queued`).
- Dependency-blocked `pending` rows are excluded from the window entirely, so
  a long chain's blocked members can't monopolize the LIMIT and starve an
  eligible successor sorted past it.

## Lanes: partitions, not priority tiers

A lane is a hard claim partition — a string on the job row (`default` unless
set), matched exactly by the claim query. **There is no ordering relationship
between lanes.**

- **Worker → lane assignment is static and 1:1.** Each `jobwarden:work`
  process serves exactly one lane, fixed at boot (`--lane=reports`). There is
  no multi-lane worker, no rebalancing, no lane weights. Relative "priority"
  *between* lanes is expressed in deployment: how many supervisors and how
  much `--capacity` each lane gets.
- **The `scheduled` lane** is the built-in isolation example: everything the
  schedule evaluator emits (and `dispatchCommand()`) is pinned to
  `lane = 'scheduled'`, served by `jobwarden:scheduled-worker` (which is
  `jobwarden:work --lane=scheduled` under a clearer name). A saturated
  business fleet can never delay cron work because the two never compete for
  the same rows.
- Batch members currently **cannot choose a lane** — they always land in
  `default`.

## No OS process priorities

Nothing in the execution path calls `proc_nice`/`setpriority`. Children —
`proc_open`'d in `child` mode, `pcntl_fork`'d in `prefork` mode — inherit the
supervisor's nice level, so all running jobs compete for CPU as equals.
`priority` decides *which* job gets a slot, never *how much CPU* it gets once
running. If a tier must also win CPU, `nice` the supervisor itself at the
service-manager level (systemd `Nice=`, container CPU shares).

## The supporting indexes

Strict-priority ordering is only viable because both queries read in index
order instead of filesorting the backlog:

| Query | Index |
|---|---|
| claim | `(lane, state, priority DESC, created_at ASC)` + a Postgres partial on `state = 'queued'` |
| admit | `(state, priority DESC, available_at)` |

The claim index is the critical one: without the direction-matched `DESC`
column, MySQL/MariaDB filesort the entire queued backlog and `FOR UPDATE SKIP
LOCKED` locks every row it sifts — exhausting the InnoDB lock table at scale.
Descending index columns need **MySQL 8.0+ / MariaDB 10.8+** (Postgres and
SQLite always support them).

## What affects *when*, at a glance

| Mechanism | Kind | Effect |
|---|---|---|
| `priority` | ordering | strict, intra-lane, claim + admission |
| `created_at` | ordering | in-band FIFO at claim; survives retries |
| `available_at` | gate | not claimable/admittable before this; never a sort key at claim |
| dependencies | gate | `pending` until every dependency `succeeded` |
| backoff | gate | pushes `available_at` on retry |
| lane | partition | which fleet may claim it, ever |
| capacity / poll cadence | throughput | claim latency, never order |
