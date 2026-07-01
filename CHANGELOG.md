# Changelog

All notable changes to `laravel-jobwarden` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
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
