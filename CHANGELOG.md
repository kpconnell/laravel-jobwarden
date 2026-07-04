# Changelog

All notable changes to `laravel-jobwarden` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Job completion results (`jobs.result`).** A handler stores a completion payload with
  `$context->result([...])`; it commits **atomically with the succeeded transition**, so a
  caller polling `GET /jobs/{id}` can never observe `state=succeeded` without its final
  result, and a fenced-out child (reaped while finishing) rolls its result back instead of
  clobbering the new owner's run. Success-only — failures keep their story in `last_error`.
  Bounded by `jobwarden.results.max_bytes` (default 64 KB): results are a completion
  summary, **not freight** — ship bulk output to a filesystem disk / S3 via
  `$context->artifact(...)` and put the artifact id in the result. Oversized or
  non-JSON-encodable payloads fail the run loudly at the call site. See
  **docs/JOB-AUTHORING.md → Results** and the polling contract in **docs/API.md**.
- **Dashboard timestamps render in the viewer's timezone.** Every `*_at` shown in the
  dashboard (created/started/finished, worker heartbeat, next-due, and log/event times) is
  now emitted as an absolute Unix-epoch value computed **in SQL** — `UNIX_TIMESTAMP()` /
  `EXTRACT(EPOCH …)`, invariant to both the app **and** the DB session timezone — and
  formatted **client-side** into the browser's own locale and zone. This fixes log/event
  stamps that previously showed the server's wall clock (UTC regardless of who was looking),
  and gives the relative "x ago" chips an exact local-time tooltip on hover. The Eloquent
  Carbon is deliberately never trusted for display, since a host whose `app.timezone` differs
  from the DB session zone mis-tags it. Backed by a new `SqlTime::epochMsExpr()` (explicit
  MariaDB/MySQL, Postgres, SQLite, SQL Server branches, plus an ANSI-interval fallback that
  runs — with accepted tz drift — on any other engine) and a `withDisplayEpochs()` model
  scope; the conversion re-applies after each Livewire poll so `wire:poll` can't revert it.
- **`JobClass::dispatch(...)` — Horizon-style dispatch (opt-in `Dispatchable` trait).**
  `ImportCatalog::dispatch('store-42', ImportMode::Full)` — positional (constructor
  order), named, or mixed args become the params JSON (enums store their backing value,
  date-times ISO-8601 with offset; models and other objects are refused at the dispatch
  site). Options chain first and `dispatch()` is always the terminal call, returning the
  created `Job` synchronously: `ImportCatalog::inLane('reports')->delay(300)->dispatch(...)`
  (`inLane`, `delay`, `availableAt`, `priority`, `maxAttempts`, `maxRuntime`, `named`,
  `idempotencyKey`, `tags`, `backoff`, `createdBy`). There is deliberately **no
  `__destruct()` commit** (unlike Laravel's `PendingDispatch`) — nothing dispatches at GC
  time. The params **round-trip through HandlerFactory before the row is created**, so a
  dispatch that would fail to bind on the worker fails at the dispatch site with no row —
  and the instance's `idempotent()` declaration **stamps the row's idempotency guard**:
  the class is the single source of truth, never repeated per dispatch. Laravel's
  Bus/Queue `dispatch()` is untouched (spec §0); `Bus::fake()` won't see these — assert
  on the jobs table.
- **Constructor param binding for job handlers — constructors are data-only; services
  move to `handle()`.** The stored params (JSON) bind to handler constructor parameters
  **by exact name**, so handlers declare typed, promoted properties instead of digging
  through `$context->params` (which is gone — one source of truth). Every constructor
  parameter is scalar / array / backed-enum / date-time, a literal mirror of the params
  JSON: backed enums coerce from their backing value, date-times from ISO-8601 strings,
  and a parameter with no matching key and no default is **refused loudly** — nothing is
  ever left for the container to invent (which would silently construct an empty model or
  a date of "now"). A **service-typed constructor parameter is refused**: `handle()` is
  now invoked **through the container**, so services method-inject per-run
  (`handle(JobContext $context, ?Mailer $mailer = null)` — declared optional because the
  interface pins the signature; the container still fills it). Eloquent models are
  deliberately never hydrated — pass the key, fetch in `handle()`, own the missing-row
  policy. The contract check runs **before** construction, so a non-JobWardenJob class
  can no longer execute constructor side effects. See **docs/JOB-AUTHORING.md** for the
  binding rules and supported types.
- **Cooperative stop flag: `$context->stopRequested()`.** The child's SIGTERM handler now
  sets a real flag (it was a documented no-op), and long-running handlers can poll it to
  checkpoint and exit cleanly within the grace window. Cooperation stays optional — a
  handler that never checks is SIGKILLed after the grace period and recorded `stopped`,
  exactly as before; correctness never depends on it. `JobContext` is now the slim
  per-attempt capability handle: identity, `result()`, `artifact()`, `stopRequested()` —
  job data lives in the constructor.

### Fixed
- **Documented: handlers must not use the `STDOUT`/`STDERR` constants — use `Log::` or
  `php://stderr`.** Prefork children close the constants to reclaim fds 1/2 into the
  per-attempt log (the dying-words capture), so `fwrite(STDERR, …)` throws in a handler
  under prefork (found by the chaos fleet backtest: the entire raw-stderr cohort failed,
  and crash-mode jobs threw before ever reaching their SIGKILL). The chaos/crash test
  fixtures now write via `php://stderr`, which opens fd 2 fresh and works identically in
  both execution modes. See **docs/JOB-AUTHORING.md → Logging**.
- **`created_at` / `updated_at` are stamped on the DB clock, not the app timezone.** Eloquent's
  automatic timestamps wrote `Carbon::now()` in `app.timezone`, leaving `created_at` — the one
  time column not already stamped with `CURRENT_TIMESTAMP` — skewed by the app↔DB-session offset.
  It surfaced once the dashboard read the true stored epoch: a job showed "created 4 hours ago"
  while `started_at` (DB-clock) was correct. `JobWardenModel` now restamps both via the query
  builder (`SqlTime::nowExpr()`, `CURRENT_TIMESTAMP` — freezable under `setTestNow`) inside the
  insert transaction, matching every other coordination timestamp and adding no extra commit. A
  caller-supplied `created_at`/`updated_at` (import/replay) is left untouched. The same drift in
  the explicit `Carbon::now()` writes is fixed too: the batch row's `started_at`/`finished_at`
  (started_at even landed *before* created_at), batch/operator cancel stamps, and job **log
  `ts`** / artifact `created_at` now use `SqlTime::nowExpr()` — so log lines and batch timings
  read in the correct frame instead of hours off.
- **`job_events.reason` is now MEDIUMTEXT** (new migration — run `php artisan migrate`).
  Failure reasons embed the exception message; past ~239 chars, strict-mode MariaDB/MySQL
  rejected the audit INSERT (error 1406) inside the failure-recording transaction itself.
- **Aggregate atomicity: a terminal attempt can no longer strand its job in `running`.**
  A worker completing/failing an attempt, and a reaper orphaning one, now move the
  attempt **and** the job (plus the recovery decision) in a **single transaction**, so
  a process dying in the microsecond window between the two former transitions can no
  longer leave `attempt=succeeded|failed|orphaned, job=running`. (Fencing already
  prevented double-execution; this closes the liveness/stranding gap.)
- **`proc_open()` spawn failure is resolved synchronously.** If a job child fails to
  launch, the already-claimed attempt is transitioned `dispatched → failed` and handed
  to recovery immediately, instead of being left as a childless `running` job for a
  reaper to (maybe) find. The phase-2 stamp write is now fault-tolerant.
- **`StateMachine::transition()` returns the true `from` state** for raw/partially-hydrated
  entities (previously it could report `from == to` when `state` wasn't already an enum).
- **Operator retry/restart is atomic** — the eligibility resets (`available_at`,
  cancellation withdrawal) and the audited `→ queued` transition now commit together.

### Added
- **Reconciliation backstop (Tier-3, leader-only).** The global reaper now heals any
  job stranded in `running` with a settled current attempt, regardless of how it arose —
  gated by a grace window (`JOBWARDEN_RECONCILE_GRACE`, default 30s) so it never races a
  healthy worker mid-completion.
- **`JobWarden\Health\JobWardenHealth`** — an invariant tripwire (`invariantViolations()`
  / `isConsistent()`) covering *no running job with a settled current attempt*, *no
  dangling `current_attempt_id`*, and *`attempt_count ≥ max(attempt_number)`*. Assert it
  is empty at the end of chaos tests.

### Changed
- **Default retry budget raised: `JOBWARDEN_MAX_ATTEMPTS` now defaults to 4 (was 1).**
  With a budget of 1, declaring a job idempotent bought nothing unless the dispatch
  site also granted attempts — the first failure or host-loss orphaning was terminal
  (`orphaned → failed (attempts exhausted)`). The budget only ever spends for
  idempotent jobs — non-idempotent jobs fail on error and park on orphan regardless —
  so the higher default is safe fleet-wide. Explicit per-job `max_attempts` and env
  overrides behave exactly as before. (The scheduled tier already defaulted
  idempotent runs to 3.)
- **The admit pass is priority-first** (new migration — run `php artisan migrate`). The
  Admitter's promotion window (`pending`/`retrying → queued`, LIMIT 200 per pass) was
  ordered by `available_at` alone, so when more rows were eligible than one window —
  routine at fleet scale — every slot went to earlier-due low-priority rows and a
  high-priority job waited passes on end while low-priority work already ran. Admission
  now orders `priority DESC, available_at ASC`, consistent with the claim's
  `priority DESC, created_at ASC`; below the window size behavior is unchanged (the
  claim re-sorts `queued` anyway). Backed by a new `(state, priority DESC, available_at)`
  index — the admit pass previously had no serving index at all and filesorted the jobs
  table every tick. Scheduling semantics now documented in
  **docs/SCHEDULING-AND-PRIORITY.md**.
- **`jobwarden:work` bundles its own Tier-2 local reaper.** The worker now spawns a
  co-resident `jobwarden:reap:local` as a separate child process, so a worker can never
  run without recovery and you never start the reaper yourself. It stays a distinct
  process (outliving a supervisor crash), and a **per-host lease** elects exactly one
  active local reaper even when several workers share a host. Standalone
  `jobwarden:reap:local` remains for advanced splits; disable bundling with
  `jobwarden.supervisor.bundle_reaper=false`. The container/systemd defaults no longer
  run a separate `local-reaper` role.

## [1.0.0-beta] - 2026-07-01

First public beta. The distributed-correctness core and the mechanical breadth are
complete and proven against SQLite, MariaDB/MySQL, and PostgreSQL.

### Added
- **Two-level durable state machine** (Job/Run + immutable Attempts) — the only writer
  of any `state` column, every transition audited in the same transaction.
- **Guarded-UPDATE ownership** (`affected == 1` = ownership) and **per-attempt fencing
  tokens** — no read-then-write anywhere in the concurrency path.
- **Claim drivers**: `FOR UPDATE SKIP LOCKED` (PG ≥ 9.5, MySQL ≥ 8.0.1, MariaDB ≥ 10.6)
  with an optimistic-CAS fallback; engine auto-detection.
- **Process supervision**: child-process-per-job execution, process stamps
  (`/proc` start-time + nonce + pidfile), Tier-1 `waitpid` reaping, drain, cancel.
- **Three-tier recovery**: Tier-1 (supervisor `waitpid`), Tier-2 (local reaper,
  `/proc`-verified), Tier-3 (global reaper, leader-leased). Tier-3 keys recovery on the
  **per-process identity** (`worker_id`), so a restart never masks a dead incarnation.
- **Idempotency-gated retry** — idempotent orphans retry with backoff; non-idempotent
  orphans park for an operator.
- **Batches & dependencies**: fan-out, chains, and arbitrary DAGs via `dependsOn`;
  failure policies (`continue` / `fail_fast` / `threshold`); cascade-cancel of
  unreachable dependents; per-member `available_at` (delay / stagger).
- **Scheduler**: cron + one-off, missed-run catch-up, overlap policy, multi-scheduler
  safe (`SKIP LOCKED` + a unique occurrence constraint); a dedicated `scheduled` lane.
- **Durable logs** (DB body sink), export/support bundle, observability read models.
- **Operator HTTP/JSON API** (read + actions + scheduling) and a **Livewire dashboard**.
- **Deployment**: a single "host" image (init baked in) that runs any set of roles via
  `JOBWARDEN_ROLES`; systemd unit templates; a Docker Compose dev stack.

[Unreleased]: https://github.com/kpconnell/laravel-jobwarden/compare/v1.0.0-beta...HEAD
[1.0.0-beta]: https://github.com/kpconnell/laravel-jobwarden/releases/tag/v1.0.0-beta
