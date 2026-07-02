# Laravel JobWarden

[![Tests](https://github.com/kpconnell/laravel-jobwarden/actions/workflows/tests.yml/badge.svg)](https://github.com/kpconnell/laravel-jobwarden/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/kpconnell/laravel-jobwarden.svg)](https://packagist.org/packages/kpconnell/laravel-jobwarden)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A durable, database-backed **job, batch & scheduling engine** for Laravel — a deliberate alternative to the
Redis/Horizon model in which **the relational database is the source of truth and the coordination layer**.
Correctness, recovery, and observability come from durable state transitions, per-process fencing tokens, an
idempotency-gated retry guard, and verifiable OS-process identity — never from assuming a worker is healthy.

> **Status: `1.0.0-beta`.** The distributed-correctness core and the full feature set are complete and proven
> against SQLite, MariaDB/MySQL, and PostgreSQL (134 tests). APIs may shift slightly before `1.0.0`.

## The core idea: positive control of every process

Horizon and every Redis/SQS/database *queue* share one blind spot: **the moment a job is handed to a worker, the
system loses sight of it.** The job becomes an in-flight payload behind a visibility timeout. Nothing knows which
OS process on which host is actually running it, whether that process is alive or wedged, or how to reach it.
Recovery is a guess on a timer, and re-delivery is blind at-least-once — which can double-run your work.

JobWarden inverts that. Every job runs in a **child process the supervisor spawned and watches**, and the
database row for that attempt carries the process's full OS identity: `host_id`, the supervisor and child
**PIDs**, each PID's `/proc` **start-time**, and a per-spawn **nonce**. A row maps to a real, reuse-proof Linux
process. The engine always knows *what is running, where, on which PID* — and can **prove** it before it acts.

That one property — **positive control** — is what unlocks everything a queue structurally cannot do:

| | Redis / Horizon / SQS queue | JobWarden |
|---|---|---|
| **What the system knows** | "a job is in flight somewhere" | the exact host + PID + start-time behind every attempt |
| **Detecting a dead/wedged worker** | wait out a visibility timeout / missed heartbeat | *verified* — `waitpid`, then `/proc` stamp checks, across three tiers |
| **Recovery latency** | bounded by the job's own timeout (a 4 h job ⇒ ~4 h) | decoupled from job duration (a dead host is caught in ~40 s) |
| **Re-delivery safety** | blind at-least-once; can silently double-run | fencing token + **binary** idempotency gate: retry *or* park |
| **Killing one specific running job** | not possible — no handle to the process | targeted SIGTERM→SIGKILL of the exact stamped PID, *confirmed dead* |
| **Reused-PID safety** | n/a | start-time match means a recycled PID is never mistaken for the original |
| **Graceful deploys** | in-flight jobs abandoned / blindly re-queued | drain: stop claiming, bleed off in-flight work, then exit |
| **Blast radius of a bad job** | can wedge or OOM the worker and its siblings | one job = one child; a crash/OOM/segfault can't take the supervisor down |

### What positive control buys you

- **Detect orphans — verified, not guessed.** Detection runs in three tiers: the supervisor `waitpid`s its own
  children instantly; a per-host local reaper checks each attempt's `/proc` stamp (catching a *dead supervisor*
  whose child reparented to `init`); and a leader-leased global reaper catches whole dead hosts via an expired
  lease. It keys on the **per-process** `worker_id`, so restarting a box never masks the incarnation that died on
  it — and a 4-hour job on a pulled-plug host is recovered in ~40 s, not 4 hours.
- **Kill a specific running job — on demand.** An operator cancel flips `cancel_requested`; the supervisor
  SIGTERMs *that exact child*, waits a grace window, then SIGKILLs. A reaper that finds a supervisor-less child
  SIGTERM→SIGKILLs it and **confirms it is dead before orphaning** — so a replacement never runs while the
  original still breathes. Every kill is start-time-checked, so a recycled PID is never the victim.
- **Isolate every job.** One job = one child process. A job that segfaults, OOMs, or blocks for an hour can't
  take down the supervisor or its siblings, and because the supervisor is *watching* it, a busy job is never
  mistaken for a dead one. Lanes and a dedicated DB connection isolate fleets and coordination traffic.
- **Bleed off work for zero-loss deploys.** On SIGTERM the supervisor stops claiming, lets its in-flight
  children finish (a bounded drain window), then exits — so a rolling deploy drains gracefully instead of
  abandoning jobs. (Prefork recycling reuses the same drain to periodically rebaseline the worker.)
- **Never double-run by accident.** Reassignment bumps a **fencing token**, so a presumed-dead worker that comes
  back can't clobber the new owner. And idempotency is binary: a lost idempotent job retries automatically; a
  non-idempotent one **parks** for an operator instead of silently running twice. Every step lands in a durable
  audit ledger.

Two more things queues make you bolt on, here for free:

- **No Redis to operate.** Your database is already durable, transactional, and backed up. JobWarden coordinates
  entirely through it (`FOR UPDATE SKIP LOCKED`, with an optimistic-CAS fallback where that isn't available).
- **Batches, DAGs & scheduling, built in** — fan-out, chains, arbitrary dependency graphs, cron, and one-off
  runs — all on the same durable substrate, all under the same positive control.

JobWarden coexists with Laravel's Bus/Queue; it does **not** hijack `dispatch()`.

## Requirements

- PHP **8.3+**, Laravel **11 or 12**
- **Linux** for the runtime (the liveness model uses POSIX signals, `proc_open`/`pcntl`, and `/proc`)
- A database — best with `SKIP LOCKED` (PostgreSQL ≥ 9.5, MySQL ≥ 8.0.1, MariaDB ≥ 10.6); others fall back to
  an optimistic claim. MariaDB on RDS is the primary production target.

## Installation

```bash
composer require kpconnell/laravel-jobwarden
php artisan jobwarden:install --migrate
```

`jobwarden:install` publishes `config/jobwarden.php` and the migrations; `--migrate` runs them. By default
JobWarden uses a **dedicated database connection** (`config('jobwarden.connection')`) so its coordination
traffic is isolated from your app's. Every setting is env-driven with sensible defaults — you don't have to
publish the config to tune it; see **[docs/CONFIGURATION.md](docs/CONFIGURATION.md)** for the full reference.

## Defining a job

A JobWarden job implements one small contract — it receives plain, JSON-serializable `params` (not a serialized
object graph), and declares whether it is safe to auto-retry. Params are **bound to constructor parameters by
name** (services resolve from the container as usual), so handlers get typed, promoted properties instead of
array digging:

```php
use JobWarden\Contracts\JobWardenJob;
use JobWarden\Runner\JobContext;

final class ImportCatalog implements JobWardenJob
{
    public function __construct(
        private readonly CatalogClient $client,   // service — container DI
        private readonly string $storeId,         // data — bound from params by name
        private readonly bool $fullSync = false,  // data — optional param
    ) {
    }

    public function handle(JobContext $context): void
    {
        // ... do the work; throwing = failure, returning = success ...
    }

    public function idempotent(): bool
    {
        return true; // lost/failed runs may be safely re-executed
    }
}
```

Backed enums and date-times coerce from their JSON representations; Eloquent models are deliberately not
hydrated (pass the key, fetch in `handle()`); the full params array also remains available as
`$context->params`. See **[docs/JOB-AUTHORING.md](docs/JOB-AUTHORING.md)** for the binding rules, supported
types, and everything available inside a run.

## Dispatching

```php
use JobWarden\JobWarden;

app(JobWarden::class)->dispatch(ImportCatalog::class, ['storeId' => 'store-42'], [
    'idempotent'   => true,
    'max_attempts' => 3,
    'priority'     => 10,
    'available_at' => now()->addMinutes(5), // optional delay
]);
```

### Batches (fan-out, chains, DAGs)

```php
app(JobWarden::class)->batch('nightly-sync', failurePolicy: 'continue')
    ->add('extract',   ExtractJob::class,   ['store_id' => 42])
    ->add('transform', TransformJob::class, ['store_id' => 42], dependsOn: ['extract'])
    ->add('load',      LoadJob::class,      ['store_id' => 42], dependsOn: ['transform'])
    ->add('report',    ReportJob::class,    [],                 dependsOn: ['load'])
    ->dispatch();
```

A member with no `dependsOn` starts immediately; one with dependencies is admitted only when **all** of them
have succeeded. Independent chains run in parallel. Failure policies: `continue`, `fail_fast`, `threshold(N)`.

### Scheduling

```php
$jw = app(JobWarden::class);
$jw->schedule('hourly-metrics', '0 * * * *', ComputeMetrics::class);      // cron → a job
$jw->scheduleCommand('nightly-prune', '0 3 * * *', 'cache:prune');        // cron → an artisan command
$jw->scheduleOnce('one-off', now()->addHour(), SendDigest::class);        // fire once
```

## Running the engine

JobWarden runs as **long-running processes**. `jobwarden:work` already brings its own Tier-2 local
reaper (a co-resident child process), so the minimum is a worker, the global reaper, and the scheduler:

```bash
php artisan jobwarden:work          # claim + run jobs — and bundle a co-resident Tier-2 reaper
php artisan jobwarden:reap:global   # Tier-3: detect dead workers fleet-wide (leader-leased)
php artisan jobwarden:schedule      # evaluate schedules
```

You never have to remember `jobwarden:reap:local` — the worker spawns it, and a per-host lease keeps
exactly one active even when several workers share a box. (It remains a standalone command for
advanced split topologies.)

Each daemon should be supervised by the OS (systemd `Restart=always`, or a container restart policy). Unit
templates are in [`packaging/systemd/`](packaging/systemd), and a container image that runs any set of roles
via a `JOBWARDEN_ROLES` env var is in [`docker/`](docker) (see [`docker-compose.yml`](docker-compose.yml) for a
full local stack).

**→ See [docs/HOSTING.md](docs/HOSTING.md)** for deployment topologies: serving the UI from your existing app
host, running everything on a single worker box, and how to scale out to a fleet.

### How recovery works (the short version)

Every claim is stamped with the claiming worker's globally-unique id and a fencing token. A worker heartbeats a
lease while it lives. When a lease goes stale, a reaper orphans that worker's in-flight attempts — bumping the
fence so the presumed-dead worker can never clobber the reassignment — and recovery re-queues idempotent jobs or
parks non-idempotent ones. Liveness is never the job's responsibility: jobs run in a child process the
supervisor watches, so a job that blocks for an hour is never mistaken for a dead one.

## Operator API & dashboard

A gated JSON API (read models + actions + scheduling) mounts under `config('jobwarden.api.prefix')`, and a
server-rendered Livewire dashboard mounts under `config('jobwarden.dashboard.prefix')`. Both sit behind an
authorization gate that defaults to local-only — open it explicitly:

```php
use JobWarden\JobWarden;

JobWarden::auth(fn ($request) => $request->user()?->can('viewJobWarden') ?? false);
```

See [`docs/API.md`](docs/API.md) for the full endpoint reference.

## Testing

```bash
composer test                       # SQLite (fast)
# full matrix (SQLite + MariaDB + Postgres) runs in the Docker stack and in CI
docker compose run --rm migrate php vendor/bin/testbench package:test
```

## License

MIT © Kevin Connell. See [LICENSE](LICENSE).
