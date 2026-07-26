# Hosting JobWarden

This guide covers how to actually lay JobWarden out on real infrastructure — from a
single box to a scaled fleet — and how the pieces relate. For every configuration knob
and how to set it see [CONFIGURATION.md](CONFIGURATION.md); for the API/dashboard
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
[`packaging/systemd/`](../packaging/systemd)). Either way, **every one of these
processes must be started and restarted by something outside JobWarden.** Getting
that right is a short list of properties, not a choice of tool — see the next
section.

## Running the roles: the launcher contract

**JobWarden recovers work. It never recovers processes.**

That is worth stating plainly, because it decides what your infrastructure is
responsible for. The Tier-3 global reaper (`jobwarden:reap:global`) recovers **jobs**:
it orphans a dead supervisor's in-flight attempts, bumps the fencing token so the dead
process can never write again, and runs recovery — so idempotent work re-runs elsewhere
and non-idempotent work parks for an operator. The Tier-2 local reaper is the only
component that touches processes at all, and it only ever *kills* them. **Nothing in the
package starts a process.**

So a supervisor that dies and never comes back is the quietest failure in the system.
Recovery does its job perfectly: no failed jobs, no stuck jobs, no invariant violated, a
completely consistent database. You have simply lost that lane's capacity, and nothing
in JobWarden will tell you.

Whatever you use to start these processes — systemd, a container restart policy, ECS,
Kubernetes, supervisord, runit, the image's own `jobwarden-host` script — this guide
calls **the launcher**. (Not "the supervisor": in JobWarden that word means the
`jobwarden:work` process itself.) It has to satisfy five properties.

### 1. Restart the process when it exits on its own — including a clean exit 0

This is the one that bites, because a supervisor exits `0` in three normal situations
and only one of them means "stay down":

| Exit | Cause | Come back? |
|---|---|---|
| `0` | SIGTERM drain that **the launcher asked for** — `systemctl stop`, `docker stop`, an ECS task stop, a pod delete | **No.** A deploy or scale-in is completing. |
| `0` | SIGTERM drain from **anything else** — an operator's `kill -TERM`, `drain_timeout` abandonment | **Yes.** |
| `0` | **Prefork recycle** — after `prefork_recycle_after` forks the master drains itself and exits, *expecting* a restart on a fresh baseline | **Yes**, or that lane stops for good. |
| non-zero | Crash, OOM kill, or five consecutive deterministic tick failures (the supervisor exits loudly on purpose so recovery sees an honest death) | **Yes.** |

Every launcher's *default* gets the exit-`0` rows wrong in the same way, because the
defaults are tuned for one-shot commands:

| Launcher | Default | Use instead |
|---|---|---|
| systemd | `Restart=no` | `Restart=always` |
| Docker / Compose | no restart policy | `restart: unless-stopped` |
| supervisord | `autorestart=unexpected` (exit `0` counts as *expected*) | `autorestart=true` |
| Kubernetes | — | `restartPolicy: Always` (the pod default) |
| ECS | — | run it as a **service** with a maintained desired-count, not a standalone task |
| `jobwarden-host` (the image) | already correct | — |

**"Always" does not fight your drains.** It reads like it would, so: every one of these
settings keys on *why the process exited*, not on overriding a stop you asked for.
`Restart=always` does not restart a unit you `systemctl stop`; Docker's `always` /
`unless-stopped` do not restart a container you `docker stop`; `autorestart=true` does not
fight `supervisorctl stop`. A launcher-initiated stop is a stop. A process that exits by
itself comes back.

### 2. Watch each supervisor process individually

One supervisor dying must trigger one restart of *that* process. The failure mode to
avoid is N processes started by a single launcher process that only reports its own PID:
Docker's restart policy, the ECS agent and the Kubernetes liveness probe then all see a
perfectly healthy container while a third of your capacity is gone. If you write your own
launcher, [`docker/jobwarden-host`](../docker/jobwarden-host) is the contract to copy — it
restarts each role in place and escalates to a whole-host exit only on a crash-loop.

### 3. Restart the process; do not sweep its process tree

A crashed supervisor leaves job children running (they reparent to PID 1) and, by design,
leaves its bundled Tier-2 local reaper running too — that reaper exists as a *separate*
process precisely so it outlives the crash and can clean up. Let it. It kills the stranded
children on the documented path (SIGTERM → grace → SIGKILL → confirm dead → orphan the
attempt with an audit trail and a job-log line → run recovery), which is strictly better
than the launcher killing them anonymously.

On systemd this needs one non-default line, because the default `KillMode=control-group`
tears down the whole cgroup when the main process dies — taking the local reaper and every
job child with it:

```ini
# /etc/systemd/system/jobwarden-work@.service
[Service]
ExecStart=/usr/bin/php artisan jobwarden:work --lane=%i --capacity=10
Restart=always
RestartSec=2
KillSignal=SIGTERM
KillMode=process       # let the co-resident reaper + job children outlive a crash
TimeoutStopSec=0       # or ≥ your longest job; see Deploys / draining
```

```sh
systemctl enable --now jobwarden-work@default jobwarden-work@reports
```

### 4. Escalate a crash-loop instead of hammering it

A supervisor that dies on every start is a code or config problem; restarting it every two
seconds forever just buries the signal. Cap the rate and hand off — systemd's
`StartLimitBurst`/`StartLimitIntervalSec`, or on the image path
`JOBWARDEN_MAX_RESTARTS` (8) within `JOBWARDEN_RESTART_WINDOW` (60s), which exits the whole
host so the runtime surfaces it and backs off.

### 5. The launcher can see a *dead* supervisor — never a *wedged* one

Only `jobwarden:reap:local` implements the systemd watchdog protocol (`Type=notify` +
`WatchdogSec`, petted only on a healthy scan). A supervisor that is alive and heartbeating
but claiming nothing looks perfectly fine to every launcher there is. That detection has to
come from the database — see [What to watch](#what-to-watch).

### Draining: ask the launcher, don't signal the PID

The corollary of property 1. `kill -TERM <supervisor-pid>` looks to the launcher like a
spontaneous exit: the supervisor drains cleanly, exits 0, and is immediately restarted and
claiming again. That is correct behaviour — it is exactly how the prefork recycle works —
but it is not what you wanted if you were trying to quiet the box. **To take a lane down,
stop it through the launcher** (`systemctl stop`, `docker stop`, scale the service to
zero). Signal the PID only when you want a drain-and-come-back.

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
  with business jobs (or vice-versa). Add your own lanes for further isolation. Serving
  several lanes from one box means several supervisors on one box — see
  [Multiple lanes on one host](#multiple-lanes-on-one-host).
- **Priority** — per-job `priority` orders the claim within a lane.

### What does *not* scale by adding boxes

- The **global reaper** is a single active leader regardless of replica count — but it
  only scans for stale worker leases, so it isn't a throughput bottleneck.
- The **database** is the shared resource. Watch three things as you grow:
  - **Connections.** Each daemon holds a small, steady number. Budget roughly
    `hosts × (roles-per-host)` against your DB's `max_connections` and any pooler.
  - **Heartbeat writes.** Every role writes a lease heartbeat once per loop iteration —
    for a supervisor that means once per poll, so a *saturated* one beats at
    `poll_min_ms` (20/sec), not on some fixed interval. `heartbeat_interval` sets the
    Tier-3 *death threshold*, not the write rate; lowering it will not reduce this
    traffic. Each beat is small, but budget `supervisors × poll rate` at scale.
  - **Claim contention.** `SKIP LOCKED` is designed for this and scales well. If you see
    idle spin (slots not staying full), the cause is usually per-job **boot cost** or the
    **poll cadence**, not the claim — reach for `prefork` and a lower `poll_interval_ms`
    before adding boxes (see [Execution model](#execution-model-child-vs-prefork)).
  - **Write rate.** Each job makes a handful of small writes (claim, transitions, audit
    event, logs). Under `prefork` — where the framework boot no longer hides it — this
    commit rate, not the workers, becomes the throughput ceiling; size the DB for it.

## Multiple lanes on one host

One supervisor serves one lane. A box serving three lanes runs three supervisors —
`jobwarden:work --lane=default`, `jobwarden:work --lane=reports`, and
`jobwarden:scheduled-worker` (which is just `--lane=scheduled` under a friendlier name).
They are independent processes with independent claim queues that happen to share a box.

### What is per-lane and what is per-host

| | Scope |
|---|---|
| Claim queue, priority ordering | per **lane** |
| `--capacity` | per **supervisor** |
| Worker row, heartbeat, Tier-3 detection | per **supervisor** (`worker_id`, fresh on every process start) |
| Tier-1 reap (`waitpid`, exit codes, forcing a crashed child terminal) | per **supervisor**, its own children only |
| Tier-2 local reaper | **one active per host**, elected by a lease — it scans every in-flight attempt on the box, whatever lane it belongs to |
| Self-fence on lost DB connectivity | **host-wide** — SIGKILLs every stamped child on the box, all lanes |

Two consequences worth internalising before you add a lane to an existing box:

- **Capacity is additive.** A box's real concurrency is the *sum* of its supervisors'
  `--capacity`, not the largest one. Adding a lane adds load; size the box for the sum.
- **Lanes isolate scheduling, not failure.** A lane is a separate queue, not a separate
  failure domain — the host lease and the self-fence are host-wide. If a lane's work must
  be untouchable by another lane's incident, give it its own host or container.

### When a supervisor dies outside a drain

"Outside a drain" means SIGKILL, an OOM kill, a segfault, five consecutive deterministic
tick failures, or the process simply vanishing. No drain, no `stopped` worker row.

**Its children do not die with it.** They reparent to PID 1 and keep running — and keep
self-reporting their own outcome, because a job child owns its result independently of its
parent. What is lost is Tier-1: nobody is `waitpid`-ing them, and nobody will force a
terminal state on one that crashes without reporting.

The other lanes are untouched — different processes, different claim queues, different
worker rows. Here is the whole sequence at stock defaults:

| When | Who | What |
|---|---|---|
| `t=0` | — | Supervisor gone. Job children reparent to PID 1 and keep running. Its worker row stops beating. |
| `≤ 5s` (`local_scan_interval`) | The host's **Tier-2** local reaper — whichever lane's supervisor happens to have bundled the active one | Verifies every in-flight attempt on this **host**. Sees "supervisor pid dead, child pid alive", then SIGTERM → `graceful_timeout` (10s) → SIGKILL → confirms dead → **only then** orphans the attempt and bumps the fence. Recovery decides: idempotent → retry (possibly on another host), non-idempotent → park. A pending cancel is still honoured. |
| immediately | **The launcher** | Restarts *that lane's* supervisor. Fresh `worker_id` and incarnation; it claims from scratch. It does not — and cannot — adopt the old children. |
| `~30s`, only if Tier 2 didn't get there | **Tier-3** global reaper | `heartbeat_interval × missed_beats` past the last beat, orphans the dead supervisor's in-flight attempts by `worker_id` and runs recovery. Jobs only — anything still *running* on that box is Tier-2's problem, not its. |

The crash path is well covered. **The failure mode to design against is the restart never
happening** — see [the launcher contract](#running-the-roles-the-launcher-contract). Tier 3
will keep the *jobs* safe indefinitely; nothing will bring the *lane* back.

### Keep a local reaper alive on the box

Because the active Tier-2 reaper is elected per host, a multi-lane box normally has several
(one bundled per worker) and only one scanning — the rest idle as hot spares, which is
exactly what you want: any single supervisor's death still leaves the box covered.

Two ways to lose that, both worth checking:

- **The launcher sweeps the crashed supervisor's process tree** (systemd's default
  `KillMode`, or a custom launcher that kills a process group). The bundled reaper dies with
  its supervisor. See property 3 of the launcher contract.
- **You set `JOBWARDEN_BUNDLE_REAPER=false` and forgot the standalone unit.** Then the box
  has no Tier 2 at all, and every stranded child waits for Tier 3 — which orphans the *job*
  without ever killing the *process*.

If the elected reaper does die with its supervisor, a spare takes over only after the lease
expires: up to `local_lease_ttl` (15s) plus a scan (5s), then the kill path (up to 13s) —
so up to ~33s before the attempt is orphaned, against a Tier-3 budget of 30s. They overlap
at stock defaults, and when Tier 3 wins the race an *idempotent* job can briefly run twice
(non-idempotent jobs park and never re-run, so this is never a state-correctness problem —
it is duplicate side effects and wasted capacity, which on an hour-long job is an hour).
Keep the margin with either:

```bash
JOBWARDEN_MISSED_BEATS=6            # 60s Tier-3 budget — noise next to hour-long jobs
# or shorten Tier-2 takeover instead:
JOBWARDEN_LOCAL_LEASE_TTL=8
JOBWARDEN_LOCAL_SCAN_INTERVAL=2
```

For a mixed fleet running hour-plus jobs, raise `MISSED_BEATS` — 30s versus 60s of
host-death detection is irrelevant at that job length, and the margin is free.

### One lane per container? Check `host_id` first

Putting each lane in its own container (a k8s pod with N containers, an ECS task with N
containers) is a clean way to satisfy properties 1, 2 and 4 of the launcher contract — the
platform restarts each container independently. But Tier 2 finds a box's work by `host_id`
and verifies it through `/proc`, so **containers sharing a `host_id` must share a PID
namespace.**

`host_id` is `sha256(machine-id : boot_id)`, and `boot_id` is the *node kernel's* — shared
by every container on it. So the discriminator is `/etc/machine-id`:

- **The JobWarden image is safe**: its entrypoint mints a per-container machine-id when one
  isn't already present, so each container gets its own `host_id` and reaps only its own work.
- **Your own app image may not be.** If a non-empty `/etc/machine-id` is baked in at build
  time, every container from that image on the same node computes the *same* `host_id`. The
  one elected local reaper then `/proc`-verifies pids belonging to other containers' PID
  namespaces, finds nothing, concludes "supervisor and child gone", and orphans healthy
  running work — silently, and it will re-dispatch idempotent jobs that are still running.

  Fix it by leaving `/etc/machine-id` empty in the image (so it's minted per container), or
  by sharing the PID namespace across the containers (`shareProcessNamespace: true` on k8s,
  `pidMode: task` on ECS) so the reaper can genuinely see them all.

### What to watch

Process death is invisible to the engine, so these signals have to come from you:

| Signal | Why |
|---|---|
| **Live supervisors per lane** | A lane with zero supervisors fleet-wide is a *perfectly healthy* JobWarden: jobs queue, nothing fails, no invariant breaks. Count `workers` rows with `role='supervisor'`, `state='active'` and a fresh `heartbeat_at`, grouped by `meta->lane`. (The dashboard groups *jobs* by lane, not workers — this one is a query today.) |
| **Oldest queued-job age per lane** | The lagging indicator for the same thing, and it catches "the lane is up but nowhere near big enough" too. |
| **Supervisors `active` past the heartbeat budget** | A wedged supervisor no launcher can see. The dashboard's dead-supervisor count surfaces this. |
| **Launcher restart rate per lane** | Restarts are normal (prefork recycles); a *rising* rate is a crash-loop the launcher is absorbing. |

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

**Recycling waits for an idle moment.** A drain claims nothing until the last in-flight
fork finishes, so recycling *at* the threshold would idle the whole box for as long as its
longest running job — on a mixed host, an hour-plus job would strand every other slot for
that entire hour. So crossing the threshold doesn't start the drain; it starts *wanting*
to. The master keeps claiming at full capacity and recycles at the first tick with nothing
in flight, which under short-job load arrives almost immediately. Only if that moment never
comes does it drain anyway, after `JOBWARDEN_PREFORK_RECYCLE_GRACE` (default 1 hour, `0` =
wait forever); the deferral is logged either way. A recycle drain also ignores
`JOBWARDEN_DRAIN_TIMEOUT` — timing out abandons children to be `SIGKILL`ed by the local
reaper, which is the right trade when the platform is stopping the container and the wrong
one for housekeeping we chose to do.

**The next ceiling is the database.** Once the boot is gone, per-job cost is dominated by
the handful of small writes each job makes (claim, state transitions, the audit event,
logs). Throughput then tracks your DB's commit rate, not the workers — size the DB (and,
on MariaDB, consider `innodb_flush_log_at_trx_commit=2` to trade ≤1s of crash durability
for much cheaper commits) before adding more worker boxes.

## Tuning knobs

The hosting-relevant subset is below (all env vars, defaults shown; recovery is governed
by the host-lease budget). For the **complete** reference — every knob, grouped, plus how
to set values (env vs publish, the `config:cache` caveat) — see
[CONFIGURATION.md](CONFIGURATION.md).

| Env | Default | Effect |
|---|---|---|
| `JOBWARDEN_CAPACITY` | 6 | Concurrent jobs per supervisor (default lane). |
| `JOBWARDEN_EXECUTION_MODE` | `child` | `child` = fresh `php` per job (full isolation, per-job boot); `prefork` = fork the booted supervisor per job (same isolation, no boot; needs `pcntl`). See [Execution model](#execution-model-child-vs-prefork). |
| `JOBWARDEN_PREFORK_RECYCLE_AFTER` | 50000 | `prefork` only: forks before the master wants a fresh baseline (0 disables). It keeps claiming until it can drain without stalling. |
| `JOBWARDEN_PREFORK_RECYCLE_GRACE` | 3600 | How long a wanted recycle waits for an idle moment before draining anyway. `0` = wait indefinitely. |
| `JOBWARDEN_SCHED_CAPACITY` | 4 | Concurrency for the scheduled lane. |
| `JOBWARDEN_HEARTBEAT_INTERVAL` | 10 | With `MISSED_BEATS`, the Tier-3 budget: how stale a heartbeat must be before a worker is declared dead. It does **not** throttle the heartbeat write — every role beats once per loop iteration. |
| `JOBWARDEN_MISSED_BEATS` | 3 | Missed beats before a worker is declared dead. Raise it (e.g. 6) on a multi-lane box to keep Tier-2 ahead of Tier-3 — see [Multiple lanes on one host](#multiple-lanes-on-one-host). |
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
  lost. **Drain through the launcher** (`systemctl stop`, `docker stop`, scale to zero),
  never with `kill -TERM` on the PID — a signalled supervisor drains and is then restarted
  right back into claiming. See
  [the launcher contract](#running-the-roles-the-launcher-contract).
- **A supervisor died and nothing restarted it.** Its jobs are safe — Tier 3 orphans and
  recovers them within the heartbeat budget — but its *lane capacity* is gone and no
  JobWarden signal fires, because the package recovers work and never processes. This is
  what the launcher contract and the [per-lane
  alerts](#what-to-watch) exist to catch.
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
