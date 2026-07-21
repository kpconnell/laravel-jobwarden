# Changelog

All notable changes to `laravel-jobwarden` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.12.0] - 2026-07-21

### Changed
- **`supervisor.execution_mode` now defaults to `prefork`** (was `child`). The supervisor
  forks per job from its already-booted image instead of `proc_open`ing a fresh
  `php artisan jobwarden:run`, removing a ~144ms framework boot from every job. Isolation
  is unchanged: each job still runs in its own killable PID, reaped by Tier-1 `waitpid`,
  starting from the pristine copy-on-write baseline.

  **Upgrading:** this takes effect on upgrade unless you set it. Exec-per-job remains
  fully supported — `JOBWARDEN_EXECUTION_MODE=child`, or pin `execution_mode` in the
  published config. Hosts without the `pcntl` extension fall back to `child`
  automatically. Note that a prefork child's exit code is `0`/the runner's `ExitCode`
  rather than exec mode's, and its stdout/stderr go to the per-attempt log — see 1.11.1,
  which this release requires for correct prefork error reporting.

## [1.11.1] - 2026-07-21

Prefork-only bug fixes. A field report on the first full day of `execution_mode=prefork`
showed **15% of failed jobs losing their error entirely**: the job row carried a
synthesized `ProcessDied: child exited with code 0 … without reporting` instead of the
real exception, which was visible only in `job_logs`. Exec mode was never affected.
No migrations; no configuration changes.

### Fixed
- **A forked child no longer runs with its stdout/stderr closed.** `ForkExecutor` closed
  the inherited `STDOUT`/`STDERR` and reopened them onto the attempt log with the
  `fopen()` handles discarded — PHP frees an unassigned resource at the end of the
  statement, closing the descriptor again. Two consequences: a fatal's dying words went
  nowhere (the supervisor always ingested a 0-byte file), and fd 1/2 were left free for
  the **next** descriptor the child opened — its own fresh DB socket — so anything in
  the host application writing to `php://stderr` wrote into the database connection.
- **The child no longer logs through the host application's channel.** The swap to the
  job_logs-only channel lived in `jobwarden:run`, which a prefork child never executes,
  so it logged through whatever `logging.default` named — inherited across the fork,
  pointed at handlers (a `php://stderr` stream, a remote sink) that a fork has no
  business writing to. With `ignore_exceptions=false` such a handler *throws*, and the
  throw unwound the child's failure report. The swap now lives in `ChildRunner`, which
  both execution modes go through.
- **The outcome is now committed before it is narrated.** `ChildRunner` wrote its
  `Job failed:` log line before `recordError()`, so a throw anywhere in the logging
  stack cost the attempt its `error` — the exact condition under which the supervisor
  synthesizes a `ProcessDied` over a diagnosable exception. The success path had the
  same exposure with a worse ending: a job that had genuinely done its work could be
  left non-terminal for the supervisor to force-**fail**.
- **A prefork exit code is meaningful again.** `hardExit()` terminated via
  `pcntl_exec('/bin/true')`, which always exits 0 — discarding the `ExitCode` the runner
  returned, and telling an operator that a child which died mid-flight "exited with
  code 0". It now carries the real code (`/bin/sh -c 'exit N'`, falling back to
  `/bin/true` / `/usr/bin/true`).
- **An exception escaping `ChildRunner` leaves a record.** Its only trace was an
  `error_log()` call — which writes through the SAPI logger's libc stderr and does *not*
  recover when fd 2 is reopened underneath it. The child now writes the class, message,
  throw site, and trace to the redirected handle, which the supervisor ingests into
  `job_logs` on reap.

## [1.11.0] - 2026-07-19

JobWarden is out of beta. This is the first stable release; it consolidates everything
shipped across the `v1.1.0-beta` … `v1.10.0-beta` pre-release tags since [1.0.0-beta].
Upgrading from any beta: require `^1.11` and run `php artisan migrate` (three
migrations landed after 1.0.0-beta: the wider `job_events.reason`, the admit-pass
index, and the searchable-tags table).

### Added
- **A supervisor-observed child death now leaves real error artifacts.** Previously
  `job_attempts.error` / `jobs.last_error` were written only by the child itself (on a
  caught exception), so a SIGKILLed/OOMed child left a failed job with no error anywhere
  — the death existed only as an events-table reason and a `term_signal` column. The
  supervisor's finalize path now synthesizes a structured error (`class`
  `JobWarden\Exceptions\ProcessDied`, message like *"child killed by signal 9 (SIGKILL)
  after 364s without reporting — possible OOM or external kill"*, context
  `{attempt, pid, duration_ms}`) onto the attempt and, per the child path's semantics,
  `jobs.last_error` — so it's set whenever the death is the job's terminal failure. A
  child-reported error keeps precedence. The supervisor also writes a warning line into
  the job's own log (step `reaped`, matching the reaper-injection seam), so a dead job's
  log stream is never info-only, and the richer death message replaces the old
  `child died: exit=… signal=…` recovery reason in the events table.
- **Batch revival: the unreachable-dependents cascade now runs in reverse.** When a
  failed/canceled/stopped member re-enters the DAG (operator retry/restart), its
  completed batch **reopens** and the dependents the system canceled as unreachable are
  revived `canceled → pending` — back to waiting on their dependencies — via a new
  system-only `canceled → pending` transition. Revival matches the cascade's exact
  `cancel_reason`, so it only ever undoes the system's own verdict, never an operator's
  cancel. The leader reconcile backstop handles lost re-entry events the same way:
  reopenable batches first, then revivable members, each revival re-firing the live
  cascade for its own dependents.
- **Dashboard: schedules are editable from the detail page.** An Edit modal on
  `/schedules/{id}` covers exactly what `PATCH /schedules/{id}` allows — name, target,
  cron, timezone, idempotency, retry budget (`max_attempts`), and the missed/overlap
  policies. The schedule's *type* is fixed: a command schedule edits its artisan
  command (arguments untouched), a job schedule its job class. `PATCH /schedules/{id}`
  accepts `name`, `timezone`, `job_class`, and `command` to match (`command` is rejected
  on non-artisan schedules). Timezone inputs in both the create and edit modals are a
  picker over PHP's timezone identifiers instead of a free-text field, and
  `POST /schedules` now validates `timezone` as a real identifier rather than any
  string.
- **Dashboard: the log tail shows `step` and the log context.** Context was always
  captured and stored (`job_logs.context`) but never rendered; each line now shows it as
  dimmed logfmt-style `key=value` pairs after the message (values JSON-encoded, so
  strings, bools, and nested arrays stay unambiguous), and the `step` column — also
  stored but never displayed — renders as a `[step]` prefix.
- **`GET /tags` — tag-filter discovery for UIs.** With no `name`, lists the distinct tag
  names currently in use, each with a `job_count`; with `?name=storeid`, lists the
  distinct values recorded for that one name instead, each with its own `job_count`.
  `?value=` prefix-filters the returned values (no trailing `*` needed — this endpoint
  always prefix-matches), so a typeahead can call it as the operator types. Neither form
  is paginated; both are capped by `limit` (default 500 for names, 100 for values). Lets
  a client populate a tag filter/autocomplete without hardcoding the tag vocabulary. See
  **docs/API.md → Tag discovery**.
- **A comprehensive OpenAPI 3.1 spec for the operator API** (`docs/openapi.yaml`) —
  every endpoint, request/response schema, enum, and error shape, cross-checked against
  the controllers/models/migrations rather than hand-guessed from **docs/API.md** alone.
  Load it into Redoc, Swagger UI, or Postman for interactive docs or a generated client;
  linked from the top of **docs/API.md**.
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
- **Reconciliation backstop (Tier-3, leader-only).** The global reaper now heals any
  job stranded in `running` with a settled current attempt, regardless of how it arose —
  gated by a grace window (`JOBWARDEN_RECONCILE_GRACE`, default 30s) so it never races a
  healthy worker mid-completion.
- **`JobWarden\Health\JobWardenHealth`** — an invariant tripwire (`invariantViolations()`
  / `isConsistent()`) covering *no running job with a settled current attempt*, *no
  dangling `current_attempt_id`*, and *`attempt_count ≥ max(attempt_number)`*. Assert it
  is empty at the end of chaos tests.

### Fixed
- **Dashboard: a verbose `last_error` no longer buries the job-detail tabs.** The
  detail page was pinned to the viewport by the shell's fixed frame, so a tall error
  panel squashed the tab body to a sliver and left Logs/Attempts/Timeline/Result
  unreachable. Job detail now scrolls as one document (matching the design prototype),
  and the stack trace is capped at 300px with its own scrollbar so a deep trace never
  pushes the tabs below the fold in the first place.
- **Dashboard: "← Jobs" on job detail preserves the list's filters.** The back link
  pointed at the bare Jobs route, dropping state/lane/tag/search/page query params that
  the browser back button kept; it now returns to the referring Jobs URL intact.
- **Global reaper now reaps stale `scheduler`/`global_reaper`/`local_reaper` rows and
  idle dead supervisors, not just supervisors with stranded work.** `deadWorkers()`
  required `role = supervisor` **and** an in-flight attempt before it would even look
  at a row, so `scheduler`/`global_reaper`/`local_reaper` — which never claim job
  attempts — could never be reaped, and a supervisor that crashed between jobs (no
  attempt in flight at the moment it died) fell through the same gap. Found in
  production: the large majority of "active" scheduler/reaper rows, and several idle
  supervisors, had heartbeats stale by hours to days yet still counted on the Fleet
  page and in `GET /stats`. Any role whose heartbeat has gone stale while still
  claiming `active`/`starting`/`draining` is now reaped unconditionally; the
  in-flight-attempt-gated rescan of `dead`/`stopped` rows (which recovers stranded
  work) stays scoped to `supervisor`. Reaped rows drop out of Fleet/`/stats`
  automatically and become eligible for `jobwarden:prune` after
  `JOBWARDEN_RETENTION_WORKERS_DAYS`.
- **Global reaper now recovers attempts abandoned via `drain_timeout`.** A supervisor
  that hits `JOBWARDEN_DRAIN_TIMEOUT` abandons its still-running children and marks its
  own worker row `stopped` on the way out — not `dead` — but `GlobalReaper`'s
  dead-worker scan only ever looked at `active`/`starting`/`draining`/`dead`, so a
  `stopped` row with a stale heartbeat was invisible to it forever. The attempt (and its
  job) stayed `running` indefinitely — found in production after a deploy landed
  mid-job. `stopped` is now scanned the same as `dead`, and a stale `stopped` worker is
  reclassified to `dead` on reap so the dashboard reflects that it stranded work rather
  than exiting clean. See **docs/CONFIGURATION.md → `JOBWARDEN_DRAIN_TIMEOUT`**.
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

### Changed
- **Dashboard Jobs list: the Job column leads with the class.** The FQCN now sits on
  top with the run name beneath it, both in the primary cell style (the class was
  previously the muted subline) — operators scan by class first.
- **The Livewire dashboard is a full operator console now — complete redesign.** A
  sidebar-shell UI (IBM Plex via Google Fonts, light/dark themes and compact/comfortable
  density persisted client-side, `wire:navigate` throughout) with live nav count badges,
  a global tag-grammar search box, and per-screen endpoint pills. Every screen rebuilt:
  **Overview** (KPI tiles that click through to filtered Jobs, a needs-attention panel
  with inline Retry/Restart, in-flight/lane charts, workers/batches summaries, a live
  `job_events` activity feed); **Jobs** (multi-select state chips OR'd together, lane
  chips, a tag filter with value typeahead backed by the tag index, and row selection
  with **bulk retry/restart/cancel/stop** — per-row state guards, one audited action per
  id, partial results reported in a toast); **Job detail** (meta grid, first-class
  Params/Tags panels, `last_error` with stack trace, and Logs / Attempts / Timeline /
  Result tabs — the log tail is a nested component polling at 2s by an id cursor that
  skips rendering while idle, so the page around it stays quiet); **Batches** (segmented
  progress bars) plus a new **batch detail page** (`/batches/{id}`) that draws the
  dependency fan-out as a DAG — lanes are the independent sub-chains (labeled by the tag
  that distinguishes them, e.g. `storeid`), columns are dependency depth, failed nodes
  stay loud while their canceled downstream dims, and big batches collapse by lane
  (pure-PHP layout, no recursive CTEs — identical on SQLite/MariaDB/Postgres);
  **Schedules** (enable switches, a create modal that now also takes timezone and
  missed/overlap policies) plus a new **schedule detail page** (`/schedules/{id}`) with
  Run-now/Enable/Delete and the `schedule_runs` history; **Workers** (role summary
  chips, a dead-supervisors warning, load/capacity bars). Existing routes, component
  names, and the `JobWarden::auth()` gate are unchanged; timestamps stay on the
  SQL-epoch viewer-timezone contract. `JOBWARDEN_DASHBOARD_POLL` now defaults to `10s`
  (was `5s`); Workers polls at 5s and the log tail at 2s regardless.
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

[1.12.0]: https://github.com/kpconnell/laravel-jobwarden/compare/v1.11.1...v1.12.0
[1.11.1]: https://github.com/kpconnell/laravel-jobwarden/compare/v1.11.0...v1.11.1
[1.11.0]: https://github.com/kpconnell/laravel-jobwarden/compare/v1.0.0-beta...v1.11.0
[1.0.0-beta]: https://github.com/kpconnell/laravel-jobwarden/releases/tag/v1.0.0-beta
