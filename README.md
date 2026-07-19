# Laravel JobWarden

[![Tests](https://github.com/kpconnell/laravel-jobwarden/actions/workflows/tests.yml/badge.svg)](https://github.com/kpconnell/laravel-jobwarden/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/kpconnell/laravel-jobwarden.svg)](https://packagist.org/packages/kpconnell/laravel-jobwarden)
[![Total Downloads](https://img.shields.io/packagist/dt/kpconnell/laravel-jobwarden.svg)](https://packagist.org/packages/kpconnell/laravel-jobwarden)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**Durable, process-aware jobs, batches, DAGs, and scheduling for Laravel.**

JobWarden is a complete background-work engine on the database you already run: queue, batch/DAG runner, cron scheduler, operator API, and dashboard in one coordinated system — with no Redis, no Horizon, and no crontab to operate.

It gives Laravel applications **positive control** over running work: every job attempt is tied to a real Linux child process with verifiable process identity, fencing tokens, durable state transitions, and idempotency-gated recovery — for jobs that are too long-running, expensive, operationally important, or unsafe to treat as anonymous queue payloads.

> **Status: stable.** `1.11.0` is the first stable release. JobWarden runs entire
> production background tiers today — six-figure job counts, dependency DAGs hundreds
> of nodes wide, 100+ live schedules, multi-host fleets — on MariaDB/RDS.

![The JobWarden operator console — a live production fleet](docs/images/dashboard-overview.png)

*The operator console watching a live production fleet — including the failures it caught, parked, and offered back for retry.*

---

## Why JobWarden exists

Most queue systems know when a job has been claimed.

They do **not** know which exact operating-system process is running it.

Once a job is handed to a worker, Redis, SQS, Horizon, and traditional queue backends mostly see an in-flight payload behind a visibility timeout or worker timeout. They cannot prove:

- which host is running the job,
- which supervisor process owns it,
- which child process is executing it,
- whether that process is still alive,
- whether a PID has been reused,
- or whether it is safe to retry the work.

For many jobs, that is fine. A quick email, notification, or cache refresh can live happily behind a visibility timeout.

But for jobs that run for minutes or hours, mutate external systems, generate expensive reports, sync marketplaces, bill customers, reconcile inventory, import large datasets, or coordinate business-critical workflows, blind at-least-once delivery is a problem.

JobWarden was built for that class of work — and once it was running, it turned out to handle the quick jobs just as well.

---

## The core idea: positive control

JobWarden does not run many jobs inside one long-lived worker process.

Instead:

1. a supervisor claims a job,
2. the supervisor spawns a dedicated child process for that job,
3. the attempt row is stamped with the child process identity,
4. the supervisor watches that child,
5. reapers verify process liveness before recovery,
6. and every reassignment is protected by a fencing token.

Each running attempt is tied to:

- `host_id`
- supervisor PID
- child PID
- each PID's `/proc` start time
- a per-spawn nonce
- a fencing token
- durable attempt state

That means a JobWarden attempt maps to a real, reuse-resistant Linux process.

The system can answer the operational question queues usually cannot:

> What exact process is running this job right now, and can we prove it before acting?

That is positive control.

---

## What positive control gives you

| Capability | Redis / Horizon / SQS-style queue | JobWarden |
|---|---|---|
| What the system knows | A job is in flight somewhere | Exact host, supervisor PID, child PID, start time, nonce, and attempt |
| Dead worker recovery | Wait for timeout / visibility window | Verify liveness through supervisor, local `/proc`, and global host leases |
| Long-running job failure | Recovery is often tied to job timeout | Recovery is decoupled from job duration |
| Re-delivery | Blind at-least-once | Fencing-token protected retry or park |
| Non-idempotent jobs | Easy to double-run accidentally | Park instead of auto-retrying |
| Cancel one running job | Usually no exact process handle | Targeted SIGTERM → SIGKILL of the verified child process |
| PID reuse safety | Not applicable / not tracked | `/proc` start-time check prevents killing the wrong reused PID |
| Deploy drains | In-flight work may be abandoned or blindly retried | Stop claiming, let children finish, then exit |
| Crash isolation | A bad job can poison the worker process | One job = one child process |
| Auditability | Usually distributed across queue/backend/logs | Durable job, attempt, event, and recovery state in the database |

---

## When to use JobWarden

JobWarden is a good fit when your jobs are:

- long-running,
- expensive to repeat,
- operationally important,
- unsafe to blindly retry,
- hard to make fully idempotent,
- part of a batch or dependency graph,
- coordinating external systems,
- or important enough that operators need to see, cancel, retry, park, or inspect them.

Examples:

- marketplace syncs,
- catalog imports,
- inventory reconciliation,
- billing runs,
- fulfillment workflows,
- ERP/WMS integrations,
- report generation,
- file processing,
- ETL jobs,
- scheduled maintenance jobs,
- multi-step operational workflows,
- and any job where "it might run twice" is not acceptable.

That was the class of work JobWarden was designed for. In the field it ended up running everything else too: the prefork execution model makes a dedicated child process cheap enough for ordinary short jobs, and lanes keep mission-critical work ahead of the routine. Production deployments run their entire background tier — thousands of short jobs a day alongside hour-long syncs — on JobWarden alone, with no Redis and no crontab.

JobWarden also **coexists** cleanly with Laravel's Bus and Queue systems. It does not hijack `dispatch()`, so you can adopt it selectively and migrate at your own pace.

---

## Design principles

JobWarden is built around a few deliberate choices.

### The database is the source of truth

JobWarden coordinates through a relational database using durable state transitions.

It does not require Redis.

Your database is already durable, transactional, backed up, observable, and part of your application's recovery story. JobWarden uses that substrate for job state, attempts, claims, fences, batches, schedules, and operator actions.

Databases with `FOR UPDATE SKIP LOCKED` work best. Where that is not available, JobWarden falls back to optimistic compare-and-swap claiming.

### Every job runs in its own child process

A job that segfaults, OOMs, blocks, or gets stuck should not take down the supervisor or poison unrelated jobs.

The supervisor owns process lifecycle. The child owns user work.

### Liveness is not the job's responsibility

A busy job should not have to heartbeat from inside user code.

If a job is legitimately blocked for an hour, that does not mean it is dead. JobWarden watches the process from outside the job.

### Recovery must be verified

JobWarden does not assume a job is dead just because time passed.

It verifies process identity and host liveness before orphaning, killing, retrying, or parking work.

### Retrying is an idempotency decision

JobWarden treats idempotency as a binary safety gate.

If a lost job is idempotent, it may be retried automatically.

If it is not idempotent, JobWarden parks it for operator review instead of silently double-running business logic.

---

## Features

### Durable jobs

Dispatch JSON-serializable job parameters into durable database state.

Each job records its lifecycle, attempts, failures, retries, cancellation requests, recovery decisions, its own log stream, and an optional completion result that commits atomically with the succeeded transition — so a poller can never observe `succeeded` without its result.

Jobs carry **searchable tags** (explicit maps plus config-promoted params on an indexed table), so operators can filter 100k+ jobs by `storeid:WMT` instead of scrolling.

### Process-aware execution

Every attempt runs in a dedicated Linux child process and records enough OS identity to verify that process later.

### Prefork throughput

Isolation does not cost you boot time. The supervisor `pcntl_fork()`s each child from its own already-booted framework — roughly **5.7× the throughput** of exec-per-job in production measurement — and periodically recycles itself through the drain path to rebaseline. Short jobs stay cheap; every job still gets its own process.

### Verified orphan detection

JobWarden has a three-tier recovery model:

1. **Supervisor watch**  
   The supervisor `waitpid`s its own children and observes exits immediately.

2. **Local reaper**  
   A per-host reaper checks `/proc` process stamps and catches children whose supervisor died.

3. **Global reaper**  
   A leader-leased global reaper detects stale workers and dead hosts across the fleet.

This lets recovery be based on verified liveness instead of guessing from job duration.

### Fencing-token recovery

Every reassignment bumps a fencing token.

If a presumed-dead worker comes back later, stale ownership cannot clobber the newer owner.

### Idempotency-gated retry

Jobs explicitly declare whether they are safe to auto-retry.

Idempotent jobs can be retried.

Non-idempotent jobs park for an operator instead of being blindly re-run.

### Targeted cancellation

Operators can cancel one specific running job.

The supervisor signals the exact stamped child process, waits a grace window, escalates if needed, and confirms the process is dead.

Start-time checks protect against PID reuse.

### Graceful drains

On shutdown, a supervisor stops claiming new work, lets existing children finish within a bounded drain window, and then exits.

This supports rolling deploys without abandoning in-flight work.

### Crash isolation

One job runs in one child process.

A crash, OOM, segfault, or blocked job does not take down the supervisor or unrelated jobs.

### Batches, chains, and DAGs

JobWarden includes durable workflow primitives:

- fan-out batches,
- sequential chains,
- arbitrary dependency graphs,
- dependency-gated admission,
- failure policies,
- batch-level observability,
- and revival: retrying a failed upstream reopens the batch and revives the dependents that were canceled as unreachable.

### Scheduling

JobWarden includes durable cron and one-off scheduling with missed-run catch-up and overlap policies.

Schedules can dispatch JobWarden jobs or Artisan commands, and are created, edited, enabled, and run on demand from the dashboard or API.

### Operator API and dashboard

JobWarden includes:

- a gated JSON API with a complete [OpenAPI 3.1 spec](docs/openapi.yaml),
- a server-rendered Livewire operator console: overview KPIs, filterable job lists with bulk retry/restart/cancel/stop, live log tails, batch DAG visualization, schedule and fleet management,
- read models,
- operator actions,
- scheduling endpoints,
- and authorization hooks.

---

## Requirements

- PHP **8.3+**
- Laravel **11 or 12**
- Linux runtime
- A relational database

The runtime uses Linux process features:

- POSIX signals,
- `proc_open`,
- `pcntl`,
- and `/proc`.

Database support:

- PostgreSQL 9.5+
- MySQL 8.0.1+
- MariaDB 10.6+
- SQLite for tests and local development

Databases with `FOR UPDATE SKIP LOCKED` are preferred. Other supported databases use an optimistic claim fallback.

MariaDB on RDS is the primary production target.

---

## Installation

```bash
composer require kpconnell/laravel-jobwarden

php artisan jobwarden:install --migrate
```

The installer publishes:

- `config/jobwarden.php`
- JobWarden migrations

The `--migrate` option runs the migrations immediately.

By default, JobWarden uses a dedicated database connection:

```php
config('jobwarden.connection')
```

That keeps coordination traffic isolated from your application's primary query workload.

Every setting is environment-driven with sensible defaults. You do not need to publish the config just to tune runtime behavior.

See [`docs/CONFIGURATION.md`](docs/CONFIGURATION.md) for the full configuration reference.

---

## Defining a job

A JobWarden job implements one small contract.

Jobs receive plain, JSON-serializable parameters and declare whether they are safe to auto-retry.

```php
use JobWarden\Contracts\JobWardenJob;
use JobWarden\Dispatch\Dispatchable;
use JobWarden\Runner\JobContext;

final class ImportCatalog implements JobWardenJob
{
    use Dispatchable;

    public function __construct(
        private readonly string $storeId,
        private readonly bool $fullSync = false,
    ) {
    }

    public function handle(JobContext $context, ?CatalogClient $client = null): void
    {
        // $client is container-injected per run.
        // Constructors are data-only.
        //
        // Do the work here.
        // Returning means success.
        // Throwing means failure.
    }

    public function idempotent(): bool
    {
        return true;
    }
}
```

The constructor carries data.

Parameters are bound to constructor arguments by name, so job handlers get typed promoted properties instead of array digging.

Services are resolved from the container into `handle()` using method injection.

Supported parameter types include primitives, arrays, backed enums, and date-time values that can be represented in JSON.

Eloquent models are deliberately not hydrated. Pass keys and fetch models inside `handle()`.

See [`docs/JOB-AUTHORING.md`](docs/JOB-AUTHORING.md) for binding rules, supported types, and the full run context.

---

## Dispatching jobs

You can dispatch through the opt-in `Dispatchable` trait:

```php
ImportCatalog::dispatch('store-42', fullSync: true);
```

You can also configure lane, delay, attempts, and named parameters:

```php
ImportCatalog::inLane('reports')
    ->delay(300)
    ->maxAttempts(3)
    ->dispatch(storeId: 'store-42');
```

Or use the service API directly:

```php
use JobWarden\JobWarden;

app(JobWarden::class)->dispatch(
    ImportCatalog::class,
    ['storeId' => 'store-42'],
    [
        'idempotent' => true,
        'max_attempts' => 3,
        'priority' => 10,
        'available_at' => now()->addMinutes(5),
    ],
);
```

The service API is useful for HTTP APIs, dashboards, internal tools, and schedules.

---

## Batches, chains, and DAGs

JobWarden supports fan-out, chains, and arbitrary dependency graphs on the same durable substrate.

```php
use JobWarden\JobWarden;

app(JobWarden::class)->batch('nightly-sync', failurePolicy: 'continue')
    ->add('extract', ExtractJob::class, ['store_id' => 42])
    ->add('transform', TransformJob::class, ['store_id' => 42], dependsOn: ['extract'])
    ->add('load', LoadJob::class, ['store_id' => 42], dependsOn: ['transform'])
    ->add('report', ReportJob::class, [], dependsOn: ['load'])
    ->dispatch();
```

A member with no dependencies starts immediately.

A member with dependencies is admitted only when all of its dependencies have succeeded.

Independent chains can run in parallel.

Failure policies:

- `continue`
- `fail_fast`
- `threshold(N)`

The dashboard draws every batch as a dependency graph — lanes are the independent sub-chains, columns are dependency depth, failed nodes stay loud while the work canceled downstream of them dims:

![A 239-node production batch rendered as a dependency DAG](docs/images/dashboard-batch-dag.png)

---

## Scheduling

JobWarden can schedule jobs and Artisan commands.

```php
use JobWarden\JobWarden;

$jw = app(JobWarden::class);

$jw->schedule(
    'hourly-metrics',
    '0 * * * *',
    ComputeMetrics::class,
);

$jw->scheduleCommand(
    'nightly-prune',
    '0 3 * * *',
    'cache:prune',
);

$jw->scheduleOnce(
    'one-off-digest',
    now()->addHour(),
    SendDigest::class,
);
```

Schedules are durable and evaluated by the JobWarden scheduler daemon. Missed runs follow a catch-up policy, overlaps follow an overlap policy, and every occurrence is recorded in `schedule_runs` — this replaces both `schedule:run` and the crontab that drives it.

![Production schedules — cron expressions, next-due, policies, per-schedule toggles](docs/images/dashboard-schedules.png)

---

## Running JobWarden

JobWarden runs as long-lived processes.

The minimum production topology is:

```bash
php artisan jobwarden:work
php artisan jobwarden:reap:global
php artisan jobwarden:schedule
```

`jobwarden:work` claims and runs jobs.

It also starts the Tier-2 local reaper as a co-resident child process. You normally do not need to run `jobwarden:reap:local` yourself.

`jobwarden:reap:global` performs fleet-wide recovery using a leader lease.

`jobwarden:schedule` evaluates schedules and admits due work.

Each daemon should be supervised by the operating system or container platform.

Examples:

- systemd with `Restart=always`
- Docker / Compose restart policies
- Kubernetes deployments
- ECS services
- other container supervisors

Systemd templates are included in [`packaging/systemd/`](packaging/systemd).

A container image that can run any set of roles through `JOBWARDEN_ROLES` is included in [`docker/`](docker).

See [`docker-compose.yml`](docker-compose.yml) for a local stack.

See [`docs/HOSTING.md`](docs/HOSTING.md) for deployment topologies, including:

- serving the UI from an existing app host,
- running everything on one worker box,
- splitting roles across hosts,
- and scaling out to a fleet.

---

## Recovery model

Every claim is stamped with:

- the claiming worker ID,
- process identity,
- attempt state,
- and a fencing token.

A worker heartbeats a lease while it is alive.

When a lease goes stale, a reaper verifies liveness before orphaning the worker's in-flight attempts.

Recovery then follows the job's idempotency declaration:

- idempotent jobs may be re-queued,
- non-idempotent jobs are parked for operator review.

Because reassignment bumps the fencing token, a stale worker cannot safely write as the current owner after recovery.

Because liveness is checked outside the job process, a long-running or blocked job is not mistaken for a dead one merely because it is busy.

---

## Cancellation model

JobWarden supports targeted cancellation of a specific running attempt.

When an operator requests cancellation:

1. the attempt is marked `cancel_requested`,
2. the owning supervisor sees the request,
3. the supervisor verifies the stamped child process,
4. it sends SIGTERM,
5. waits a configured grace period,
6. escalates to SIGKILL if necessary,
7. and confirms the process is dead.

If a reaper finds a supervisor-less child, it uses the same verified process identity before killing or orphaning the attempt.

PID reuse is guarded by `/proc` start-time checks.

---

## Deploy and shutdown behavior

When a supervisor receives a shutdown signal, it drains.

Drain behavior:

1. stop claiming new jobs,
2. continue watching existing children,
3. allow in-flight work to finish within a bounded window,
4. exit cleanly.

This makes rolling deploys much safer for long-running jobs.

The same drain mechanism is also used by prefork recycling to periodically rebaseline workers.

---

## Operator API and dashboard

JobWarden includes a gated JSON API and a Livewire dashboard.

The Jobs screen filters by state, lane, and indexed tags, with bulk retry/restart/cancel/stop across a selection:

![The Jobs screen filtering 139k production jobs](docs/images/dashboard-jobs.png)

Job detail shows the bound constructor params, tags, attempts, an event timeline, the completion result, and a live log tail:

![Job detail with params, tags, and a live log tail](docs/images/dashboard-job-detail.png)

The API mounts under:

```php
config('jobwarden.api.prefix')
```

The dashboard mounts under:

```php
config('jobwarden.dashboard.prefix')
```

Both are protected by an authorization gate that defaults to local-only.

Open access explicitly:

```php
use JobWarden\JobWarden;

JobWarden::auth(
    fn ($request) => $request->user()?->can('viewJobWarden') ?? false
);
```

See [`docs/API.md`](docs/API.md) for the endpoint reference.

---

## Testing

Run the package test suite:

```bash
composer test
```

That runs the fast SQLite suite.

The full database matrix runs in Docker and CI:

```bash
docker compose run --rm migrate

php vendor/bin/testbench package:test
```

The test matrix covers SQLite, MariaDB/MySQL, and PostgreSQL.

---

## JobWarden vs Laravel Queue + Horizon

JobWarden began as a companion to Horizon, built for the jobs a queue couldn't hold safely. After running entire production background tiers on it, the honest summary is simpler: for most Laravel applications, JobWarden replaces the queue driver, Horizon, `Bus::batch`, and the crontab behind `schedule:run` — on infrastructure you already operate.

| | Laravel Queue + Horizon | JobWarden |
|---|---|---|
| Infrastructure | Redis, plus cron for `schedule:run` | the relational database you already run |
| A job in flight is | an opaque payload behind a timeout | a verified Linux process: host, supervisor PID, child PID, start time, fencing token |
| Dead-worker recovery | wait out the timeout, redeliver blindly | three-tier verified liveness, then idempotency-gated retry or park |
| Non-idempotent work | at-least-once — double-run risk | parked for an operator instead of silently re-run |
| Workflows | `Bus::batch` and chains | fan-out, chains, arbitrary DAGs, failure policies, batch revival |
| Scheduling | `schedule:run` driven by cron | durable scheduler daemon: catch-up, overlap policies, live editing, run history |
| Cancel one running job | no targeted process handle | verified SIGTERM → SIGKILL of the exact child |
| History and audit | ephemeral Redis metrics | SQL-queryable jobs, attempts, events, logs, and results |
| Crash isolation | one bad job can poison a long-lived worker | one job = one child process |
| Raw throughput | Redis wins for floods of sub-second jobs | prefork makes isolation cheap, but a database claim is still not a Redis `BRPOP` |

The last row is the honest carve-out. If your workload is hundreds of thousands of sub-second, fire-and-forget, naturally idempotent jobs per hour — and you don't need per-job history — a Redis queue remains the right tool. For everything else, and especially for the work your business actually depends on, the database-backed model buys durability, verifiable recovery, and operator control that a visibility timeout cannot express.

Migration can be incremental: JobWarden does not touch `dispatch()`, so it coexists with an existing Horizon deployment and can absorb it lane by lane, job by job.

---

## Current status

JobWarden is **stable** as of `1.11.0`.

The execution core, three-tier recovery, batching/DAGs, scheduling, dashboard, and API shipped and hardened across ten public betas, driven by production fleets — the dashboards in this README are screenshots of one. The distributed-correctness core is exercised against SQLite, MariaDB/MySQL, and PostgreSQL in CI, plus chaos testing (kill -9, OOM, dead hosts, deploy drains) against the real process supervisor.

Feedback, issues, and real-world reports are welcome.

---

## Documentation

- [`docs/CONFIGURATION.md`](docs/CONFIGURATION.md)
- [`docs/JOB-AUTHORING.md`](docs/JOB-AUTHORING.md)
- [`docs/API.md`](docs/API.md)
- [`docs/HOSTING.md`](docs/HOSTING.md)

---

## License

MIT © Kevin Connell.

See [LICENSE](LICENSE).
