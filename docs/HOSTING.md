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
- **The worker and its local reaper must be co-resident.** The local reaper
  (`jobwarden:reap:local`) verifies a worker's child processes via `/proc` on the
  *same* host, so wherever a `supervisor` or `scheduled-worker` runs, a `local-reaper`
  must run beside it (same box / same PID namespace).

## The roles

| Role | Command | What it does | Placement |
|---|---|---|---|
| `supervisor` | `jobwarden:work` | Claims + runs jobs on the **default** lane (one child process per job). | Any number of hosts. |
| `scheduled-worker` | `jobwarden:scheduled-worker` | Same, for the isolated **scheduled** lane. | Any number of hosts. |
| `local-reaper` | `jobwarden:reap:local` | Tier-2 recovery: `/proc`-verifies this host's children, fast. | **Co-resident with the workers on its host.** |
| `scheduler` | `jobwarden:schedule` | Materializes due schedule runs. | Run 1+; concurrent-safe. |
| `global-reaper` | `jobwarden:reap:global` | Tier-3 recovery: detects dead workers fleet-wide by stale lease. | Run 1+; only the lease holder is active. |
| `dashboard` | (HTTP) the operator API + Livewire UI | Stateless read/write over the DB. | Anywhere with DB access. |

With the container image, a host's roles are chosen by one env var:

```bash
JOBWARDEN_ROLES="supervisor,scheduled-worker,local-reaper,scheduler,global-reaper"
```

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

## Topology 1 — the smallest real deployment

Your existing API host serves the UI; one worker box does all the work; one database
holds all the state.

```
┌────────────────────────┐         ┌──────────────────────────────────────┐
│  Existing API/web host  │        │  Worker box (1 VM or 1 container)     │
│  (your Laravel app)     │        │  JOBWARDEN_ROLES=                     │
│   + JobWarden UI/API     │       │    supervisor, scheduled-worker,      │
│     (auto-mounted)       │       │    local-reaper, scheduler,           │
└───────────┬────────────┘         │    global-reaper                      │
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
   │ local-reaper │  │ local-reaper │  │ local-reaper │           │
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
- **Pinned (tidier at scale):** run `supervisor,local-reaper` on the worker fleet, and
  `scheduler,global-reaper` (plus a spare for HA) on one or two small "control" hosts.
  Fewer idle daemons, one place to look for scheduling/reaping logs.

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
  - **Claim contention.** `SKIP LOCKED` is designed for this and scales well; if you
    see idle spin, tune `poll_interval_ms` and `--capacity` rather than adding boxes.

## Substrates

The **image is the portable unit** — init (`tini`) is baked in and roles are env-driven —
so the same artifact runs everywhere. Only the wrapper differs. JobWarden's runtime is
**Linux-only** (it uses `/proc`, POSIX signals, `proc_open`/`pcntl`).

- **Bare metal / VM (systemd).** One unit per role from
  [`packaging/systemd/`](../packaging/systemd), `Restart=always`. Ensure
  `/etc/machine-id` exists (it does on any normal install).
- **Docker.** The image + `JOBWARDEN_ROLES` + `restart: unless-stopped`. See
  [`docker-compose.yml`](../docker-compose.yml) for a full local stack; scale worker
  hosts with `docker compose up -d --scale host=N`.
- **ECS / ACI.** One task/container group per host role-set, image + `JOBWARDEN_ROLES`,
  desired-count to scale. The baked-in init means you don't rely on any
  runtime-specific "init process" flag.
- **Kubernetes.** A `Deployment` (replicas = worker boxes) running
  `supervisor,local-reaper`; a small `Deployment` for `scheduler,global-reaper`; the UI
  lives in your app's existing Deployment. If you split the supervisor and local reaper
  into separate containers, they must share a PID namespace
  (`shareProcessNamespace: true`).

## Tuning knobs

All are env vars (defaults shown); recovery is governed by the host-lease budget.

| Env | Default | Effect |
|---|---|---|
| `JOBWARDEN_CAPACITY` | 6 | Concurrent jobs per supervisor (default lane). |
| `JOBWARDEN_SCHED_CAPACITY` | 4 | Concurrency for the scheduled lane. |
| `JOBWARDEN_HEARTBEAT_INTERVAL` | 10 | Seconds between worker lease heartbeats. |
| `JOBWARDEN_MISSED_BEATS` | 3 | Missed beats before a worker is declared dead. |
| `JOBWARDEN_POLL_INTERVAL_MS` | 500 | How often a supervisor polls for claimable work. |
| `JOBWARDEN_GLOBAL_LEASE_TTL` | 15 | Global-reaper leader lease TTL (failover time). |
| `JOBWARDEN_LOCAL_SCAN_INTERVAL` | 5 | Tier-2 local reaper scan cadence. |
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
