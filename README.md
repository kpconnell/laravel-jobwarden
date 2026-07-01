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

## Why

- **Survives worker death.** A worker (or its whole host) can crash mid-job. A reaper detects the dead
  process by its stale lease, orphans its in-flight work with a bumped fencing token, and re-runs it elsewhere —
  and every step is recorded in a durable audit ledger.
- **No Redis to operate.** Your database is already durable, transactional, and backed up. JobWarden coordinates
  entirely through it (`FOR UPDATE SKIP LOCKED`, with an optimistic-CAS fallback where that isn't available).
- **Idempotency is a first-class, binary decision.** Each job declares `idempotent()`. Lost idempotent jobs
  retry automatically; non-idempotent ones **park** for an operator instead of silently double-running.
- **Batches, DAGs, and scheduling** are built in — fan-out, chains, arbitrary dependency graphs, cron, and
  one-off runs — all on the same durable substrate.

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
traffic is isolated from your app's.

## Defining a job

A JobWarden job implements one small contract — it receives plain, JSON-serializable `params` (not a serialized
object graph), and declares whether it is safe to auto-retry:

```php
use JobWarden\Contracts\JobWardenJob;
use JobWarden\Runner\JobContext;

final class ImportCatalog implements JobWardenJob
{
    public function handle(JobContext $context): void
    {
        $storeId = $context->params['store_id'];
        // ... do the work; throwing = failure, returning = success ...
    }

    public function idempotent(): bool
    {
        return true; // lost/failed runs may be safely re-executed
    }
}
```

## Dispatching

```php
use JobWarden\JobWarden;

app(JobWarden::class)->dispatch(ImportCatalog::class, ['store_id' => 42], [
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

JobWarden runs as **long-running processes**. The minimum is a supervisor and the two reapers:

```bash
php artisan jobwarden:work          # claim + run jobs (a supervisor)
php artisan jobwarden:reap:local    # Tier-2: detect dead children on this host (/proc-verified)
php artisan jobwarden:reap:global   # Tier-3: detect dead workers fleet-wide (leader-leased)
php artisan jobwarden:schedule      # evaluate schedules
```

Each daemon should be supervised by the OS (systemd `Restart=always`, or a container restart policy). Unit
templates are in [`packaging/systemd/`](packaging/systemd), and a container image that runs any set of roles
via a `JOBWARDEN_ROLES` env var is in [`docker/`](docker) (see [`docker-compose.yml`](docker-compose.yml) for a
full local stack).

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
