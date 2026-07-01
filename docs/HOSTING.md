# Hosting JobWarden

This guide covers how to actually lay JobWarden out on real infrastructure — from a
single box to a scaled fleet — and how the pieces relate. For the API/dashboard
endpoint reference see [API.md](API.md); for defining and dispatching jobs see the
[README](../README.md).

## The mental model

**The database is the coordinator. Everything else is stateless, long-running
processes.** A JobWarden "host" is just a process (or a box running several) that
takes on one or more **roles**. There is no broker, no leader you configure, no
sticky state on any host — you scale by adding processes, and the database sorts out
who claims what. A host can vanish at any instant; its in-flight work is detected and
re-run elsewhere.

Two consequences that shape every topology below:

- **Roles are cheap to co-locate.** The singletons (scheduler, global reaper) are
  safe to run on *every* host — the DB elects who is actually active (a leader lease
  for the global reaper; `SKIP LOCKED` + a unique-occurrence constraint for the
  scheduler). You never have to designate "the scheduler box."
- **A worker brings its own local reaper.** `jobwarden:work` (and
  `jobwarden:scheduled-worker`) automatically spawn a co-resident Tier-2 local reaper
  as a *separate* child process, so you can never run a worker without recovery and
  never have to remember to start the reaper. It's a distinct process on purpose — it
  outlives a supervisor crash to clean up its children — and a per-host lease keeps
  exactly one active even when several workers share a box.

## The roles

| Role | Command | What it does | Placement |
|---|---|---|---|
| `supervisor` | `jobwarden:work` | Claims + runs jobs on the **default** lane (a separate process per job — see [Execution model](#execution-model-child-vs-prefork)). | Any number of hosts. |
| `scheduled-worker` | `jobwarden:scheduled-worker` | Same, for the isolated **scheduled** lane. | Any number of hosts. |
| `local-reaper` | `jobwarden:reap:local` | Tier-2 recovery: `/proc`-verifies this host's children, fast. | **Bundled into each worker** (one active per host, leased). Run standalone only for advanced splits. |
| `scheduler` | `jobwarden:schedule` | Materializes due schedule runs. | Run 1+; concurrent-safe. |
| `global-reaper` | `jobwarden:reap:global` | Tier-3 recovery: detects dead workers fleet-wide by stale lease. | Run 1+; only the lease holder is active. |
| `dashboard` | (HTTP) the operator API + Livewire UI | Stateless read/write over the DB. | Anywhere with DB access. |

With the container image, a host's roles are chosen by one env var:

```bash
JOBWARDEN_ROLES="supervisor,scheduled-worker,scheduler,global-reaper"
```

(No `local-reaper` in the set — each worker bundles one. Add it only for an advanced
split topology where you run the reaper as its own process.)

On bare metal / a VM, each role is a systemd unit instead (see
[`packaging/systemd/`](../packaging/systemd)). Either way: **every daemon must be
supervised by the platform** (systemd `Restart=always`, or a container restart
policy) — JobWarden assumes a dead process comes back.

## The UI: host it alongside your existing app

The operator **API and dashboard are just Laravel routes + Livewire components**
that `JobWardenServiceProvider` mounts automatically (gated by
`jobwarden.api.enabled` / `jobwarden.dashboard.enabled`). So if you already run a
Laravel web/API host and you `composer require` the package there, **the UI is
already served from that host** — under `jobwarden.api.prefix` and
`jobwarden.dashboard.prefix` — with nothing extra to deploy. It only needs:

1. The package installed and pointed at the JobWarden DB connection
   (`jobwarden.connection`).
2. The authorization gate opened deliberately (it defaults to local-only):

   ```php
   JobWarden::auth(fn ($request) => $request->user()?->can('viewJobWarden') ?? false);
   ```

The dashboard reads and writes only the JobWarden tables; it does **not** need to be
co-resident with any worker. Point it at the same database and it shows the whole
fleet. **Never expose the ungated dashboard publicly** — it can read job params and
results and drive operator actions.

> Only run the standalone `dashboard` *role* from the image when you want the UI on a
> box that *isn't* already running your Laravel app.

## Already have a fleet? It's just artisan commands

You do **not** need the JobWarden container image. The image + `JOBWARDEN_ROLES` launcher
is a convenience for greenfield setups that want a single artifact. If you already build
and run your own Laravel app image (an ECS fleet, k8s, systemd on VMs…), JobWarden is
simply **a few long-running `php artisan` commands** you run on *your* image:

1. `composer require kpconnell/laravel-jobwarden` and rebuild your app image.
2. The **UI is already served** by any web instance running that image (auto-mounted, see
   above) — nothing to deploy for it.
3. Run these as long-running processes, using your image with the container command
   overridden. Nothing beyond your DB connection is required:

   | Process | Command | How many |
   |---|---|---|
   | Workers | `php artisan jobwarden:work` | scale to taste — each bundles its Tier-2 reaper |
   | Global reaper | `php artisan jobwarden:reap:global` | 1–2 (leader-leased; the fleet-wide backstop) |
   | Scheduler | `php artisan jobwarden:schedule` | 1+ (only if you use scheduling) |

That's the entire runtime — no JobWarden image, no `JOBWARDEN_ROLES`.

- **ECS** — two services on your existing task image: a **worker** service (container
  command `["php","artisan","jobwarden:work"]`, desired-count = N, autoscaled), and a
  small **control** service (one task, two containers running `jobwarden:schedule` and
  `jobwarden:reap:global`, desired-count 1 — or 2 for HA; the leader lease keeps one
  global reaper active and schedulers are `SKIP LOCKED`-safe).
- **Kubernetes** — the same commands as a worker `Deployment` (replicas = N) and a
  1-replica control `Deployment`.
- **VMs** — three systemd units (see [`packaging/systemd/`](../packaging/systemd)).

The container image below is worth it only when you *don't* already have a way to run a
long-running process — then it hands you one artifact that runs any subset of roles.

## Topology 1 — the smallest real deployment (using the image)

Your existing API host serves the UI; one worker box does all the work; one database
holds all the state.

```
┌────────────────────────┐         ┌──────────────────────────────────────┐
│  Existing API/web host  │        │  Worker box (1 VM or 1 container)     │
│  (your Laravel app)     │        │  JOBWARDEN_ROLES=                     │
│   + JobWarden UI/API     │       │    supervisor, scheduled-worker,      │
│     (auto-mounted)       │       │    scheduler, global-reaper           │
└───────────┬────────────┘         │    (workers bundle their reaper)      │
            │                      └───────────────────┬──────────────────┘
            │      both talk only to the DB            │
            └───────────────┬─────────────────────────┘
                            ▼
                 ┌─────────────────────┐
                 │   Database (RDS      │
                 │   MariaDB, etc.)     │
                 │  = the whole state   │
                 └─────────────────────┘
```

That single worker box runs every job, every batch, every schedule, and reaps its own
failures. It is a complete, correct deployment. Vertical headroom comes from
`--capacity` (concurrent jobs per supervisor, default 6).

This is the right starting point for most apps: **one worker box + your existing web
host + the DB.**

## Topology 2 — scaling out

When one box isn't enough, add worker boxes. Nothing else changes — the `SKIP LOCKED`
claim distributes jobs across whoever is asking, and recovery is fleet-wide.

```
   ┌─────────────┐   ┌─────────────┐   ┌─────────────┐      API host (UI)
   │ Worker box 1 │  │ Worker box 2 │  │ Worker box 3 │           │
   │ supervisor   │  │ supervisor   │  │ supervisor   │           │
   │ (+ reaper)   │  │ (+ reaper)   │  │ (+ reaper)   │           │
   │ (scheduler)  │  │ (scheduler)  │  │ (scheduler)  │           │
   │ (global-reap)│  │ (global-reap)│  │ (global-reap)│           │
   └──────┬───────┘  └──────┬───────┘  └──────┬───────┘           │
          └─────────────────┴──────────────┬──┴───────────────────┘
                                            ▼
                                   ┌─────────────────┐
                                   │    Database      │
                                   └─────────────────┘
```

Two equally valid ways to place the singletons:

- **Everywhere (simplest):** give every worker box the full role set including
  `scheduler` and `global-reaper`. The DB keeps exactly one of each active; the rest
  idle at near-zero cost. Add/remove boxes freely with no special "control" node.
- **Pinned (tidier at scale):** run `supervisor` on the worker fleet (each bundles its
  reaper), and `scheduler,global-reaper` (plus a spare for HA) on one or two small
  "control" hosts. Fewer idle daemons, one place to look for scheduling logs.

### Scaling levers

- **`--capacity`** — concurrent jobs per supervisor (vertical). Raise it until the box
  saturates CPU/IO or the DB connection budget.
- **More worker boxes** — horizontal throughput. Claims fan out automatically.
- **Lanes** — the `default` and `scheduled` lanes are separate claim queues. Dedicate a
  pool of `scheduled-worker` hosts if scheduled/cron-triggered work must not compete
  with business jobs (or vice-versa). Add your own lanes for further isolation.
- **Priority** — per-job `priority` orders the claim within a lane.

### What does *not* scale by adding boxes

- The **global reaper** is a single active leader regardless of replica count — but it
  only scans for stale worker leases, so it isn't a throughput bottleneck.
- The **database** is the shared resource. Watch three things as you grow:
  - **Connections.** Each daemon holds a small, steady number. Budget roughly
    `hosts × (roles-per-host)` against your DB's `max_connections` and any pooler.
  - **Heartbeat writes.** Each worker writes one lease heartbeat every
    `host_lease.heartbeat_interval` (default 10s) — cheap, but it's `N/interval`
    writes/sec at scale.
  - **Claim contention.** `SKIP LOCKED` is designed for this and scales well. If you see
    idle spin (slots not staying full), the cause is usually per-job **boot cost** or the
    **poll cadence**, not the claim — reach for `prefork` and a lower `poll_interval_ms`
    before adding boxes (see [Execution model](#execution-model-child-vs-prefork)).
  - **Write rate.** Each job makes a handful of small writes (claim, transitions, audit
    event, logs). Under `prefork` — where the framework boot no longer hides it — this
    commit rate, not the workers, becomes the throughput ceiling; size the DB for it.

## Substrates (deploying the JobWarden image)

> This section is the **image** path. If you're bringing the package into your own app
> image, use ["Already have a fleet?"](#already-have-a-fleet-its-just-artisan-commands)
> above — you just run the artisan commands and can skip the image entirely.

The **image is a portable unit** — init (`tini`) is baked in and roles are env-driven —
so the same artifact runs everywhere. Only the wrapper differs. JobWarden's runtime is
**Linux-only** (it uses `/proc`, POSIX signals, `proc_open`/`pcntl`).

- **Bare metal / VM (systemd).** One unit per role from
  [`packaging/systemd/`](../packaging/systemd), `Restart=always`. Ensure
  `/etc/machine-id` exists (it does on any normal install).
- **Docker.** The image + `JOBWARDEN_ROLES` + `restart: unless-stopped`. See
  [`docker-compose.yml`](../docker-compose.yml) for a full local stack; scale worker
  hosts with `docker compose up -d --scale host=N`. The image clears its baked config
  cache at container start so runtime `JOBWARDEN_*` overrides actually apply — **if you
  bake your own image and run `php artisan config:cache`, a baked cache freezes `env()`
  at build time**, so either set these vars at build or clear the cache on boot.
- **ECS / ACI.** One task/container group per host role-set, image + `JOBWARDEN_ROLES`,
  desired-count to scale. The baked-in init means you don't rely on any
  runtime-specific "init process" flag.
- **Kubernetes.** A `Deployment` (replicas = worker boxes) running `supervisor` (each
  pod's worker bundles its Tier-2 reaper as a child in the same container); a small
  `Deployment` for `scheduler,global-reaper`; the UI lives in your app's existing
  Deployment. (Only if you take the advanced split — reaper in its *own* container —
  do the two need a shared PID namespace, `shareProcessNamespace: true`.)

## Execution model: `child` vs `prefork`

Every claimed job runs in its **own process** — a distinct PID the supervisor
`waitpid`s (Tier-1), can `SIGKILL` to cancel, and whose crash, leak, or OOM is fully
contained. *How that process is created* is set by `JOBWARDEN_EXECUTION_MODE`:

- **`child` (default)** — the supervisor `proc_open`s a fresh `php artisan
  jobwarden:run` per job. Maximum isolation, but every job pays a full framework
  **cold boot** (tens to ~150ms of CPU + I/O, depending on your app and opcache) before
  it runs a line of handler code.
- **`prefork`** — the supervisor boots the framework **once** and `pcntl_fork()`s per
  job. The child inherits the booted framework copy-on-write, runs one job in-process,
  and exits. It keeps the *same* per-job isolation — a separate, `waitpid`-able,
  `SIGKILL`-able PID with its own address space and a clean copy-on-write slate (no
  cross-job state carryover) — but **eliminates the per-job boot**. It needs the `pcntl`
  extension (already part of JobWarden's Linux runtime) and falls back to `child` where
  `pcntl` is unavailable.

**When to reach for `prefork`:** high job rates with short handlers, where the framework
boot dominates per-job cost. In load testing it lifted sustained throughput several-fold
(the boot went from the dominant cost to ~zero). For long-running jobs (seconds and up)
the boot is noise — `child` is simpler and perfectly fine.

**What it costs:** immediately after the fork the child reconnects its own DB handle —
the one resource a fork must not share with its parent (a graceful close on the inherited
socket would drop the *parent's* connection). That reconnect is ~1ms against the ~100ms+
boot it avoids. The supervisor itself stays pristine and periodically recycles
(`JOBWARDEN_PREFORK_RECYCLE_AFTER`, default 50000 forks) so no slow parent-side leak can
seep into the copy-on-write baseline: the worker drains its in-flight forks, exits, and
its supervisor (the image launcher, or systemd/k8s for a bare command) brings it right
back.

**The next ceiling is the database.** Once the boot is gone, per-job cost is dominated by
the handful of small writes each job makes (claim, state transitions, the audit event,
logs). Throughput then tracks your DB's commit rate, not the workers — size the DB (and,
on MariaDB, consider `innodb_flush_log_at_trx_commit=2` to trade ≤1s of crash durability
for much cheaper commits) before adding more worker boxes.

## Tuning knobs

All are env vars (defaults shown); recovery is governed by the host-lease budget.

| Env | Default | Effect |
|---|---|---|
| `JOBWARDEN_CAPACITY` | 6 | Concurrent jobs per supervisor (default lane). |
| `JOBWARDEN_EXECUTION_MODE` | `child` | `child` = fresh `php` per job (full isolation, per-job boot); `prefork` = fork the booted supervisor per job (same isolation, no boot; needs `pcntl`). See [Execution model](#execution-model-child-vs-prefork). |
| `JOBWARDEN_PREFORK_RECYCLE_AFTER` | 50000 | `prefork` only: forks before the master drains + restarts for a fresh baseline (0 disables). |
| `JOBWARDEN_SCHED_CAPACITY` | 4 | Concurrency for the scheduled lane. |
| `JOBWARDEN_HEARTBEAT_INTERVAL` | 10 | Seconds between worker lease heartbeats. |
| `JOBWARDEN_MISSED_BEATS` | 3 | Missed beats before a worker is declared dead. |
| `JOBWARDEN_POLL_INTERVAL_MS` | 500 | How often a supervisor polls for work **and refills freed slots**. Jobs that finish faster than this leave slots idle between cycles — lower it for high-rate short jobs (pairs with `prefork`). |
| `JOBWARDEN_GLOBAL_LEASE_TTL` | 15 | Global-reaper leader lease TTL (failover time). |
| `JOBWARDEN_LOCAL_SCAN_INTERVAL` | 5 | Tier-2 local reaper scan cadence. |
| `JOBWARDEN_LOCAL_LEASE_TTL` | 15 | Per-host lease TTL electing the single active local reaper. |
| `JOBWARDEN_BUNDLE_REAPER` | true | Whether `jobwarden:work` bundles its own reaper (`false` for advanced splits). |
| `JOBWARDEN_MAX_RUNTIME_SEC` | — | Kill/flag a job child that runs past this budget. |

**Recovery latency ≈ `HEARTBEAT_INTERVAL × MISSED_BEATS`** (default ~30s) — the window
between a worker box dying and its jobs being re-queued. Lower it for faster recovery
(more heartbeat writes); raise it to tolerate longer GC pauses / network blips.

## Operations

- **Deploys / draining.** A `SIGTERM` to a supervisor drains it: it stops claiming and
  lets in-flight children finish, then exits. Roll deploys by bringing up new hosts and
  draining old ones; any work that doesn't finish in the grace window is recovered, not
  lost.
- **Database outage.** Workers can't claim or heartbeat, so they idle and retry; nothing
  is lost because the DB is the only source of truth. On recovery they resume; any
  worker that was declared dead during the outage simply loses its claims to the fence.
- **Backups = the DB.** The entire state — jobs, batches, schedules, attempts, the audit
  ledger, logs — lives in the JobWarden tables. Back up the database and you've backed
  up everything.
- **Observability.** Daemon lifecycle goes to **stdout** (`docker logs` / `journald`);
  per-job history lives in the **`jobwarden_job_events`** ledger and
  **`jobwarden_job_logs`**; the **dashboard** is the live view.
- **Connection isolation.** JobWarden uses a dedicated connection
  (`jobwarden.connection`); give it its own DB user/pool so its coordination traffic is
  isolated from your app, and so you can size and monitor it independently. On
  MySQL/MariaDB the provider forces `READ COMMITTED` + `MYSQL_ATTR_FOUND_ROWS` on that
  connection — no server-level change required.
