# Configuring JobWarden

Every knob lives in **`config/jobwarden.php`** and is driven by an environment
variable with a sensible default. This page explains **how to set values** (the part
that trips people up) and lists **every option**. For laying processes out on
infrastructure see [HOSTING.md](HOSTING.md); for the HTTP surface see [API.md](API.md).

> `config/jobwarden.php` is the authoritative, inline-documented source. This page
> groups and explains it — if the two ever disagree, the config file wins.

## How configuration works

The service provider **merges the package config in automatically**
(`mergeConfigFrom`), so the config is active the moment you install the package — you
do **not** need to publish anything. Each value is `env('JOBWARDEN_…', default)`, so:

**Just set the environment variable.** In `.env`:

```dotenv
JOBWARDEN_CAPACITY=10
JOBWARDEN_POLL_INTERVAL_MS=250
JOBWARDEN_EXECUTION_MODE=prefork
```

…or as real environment variables on the process (this is what the container image and
its `docker-compose.yml` do — one `JOBWARDEN_*` block for the whole fleet), or in a
systemd unit's `Environment=`. The package's `config/jobwarden.php` reads them directly.

### Publishing the config is optional — and has a sharp edge

`php artisan jobwarden:install` (or `vendor:publish --tag=jobwarden-config`) copies the
file to your app's `config/jobwarden.php`. Only do this to change a value that **isn't**
env-driven. Be aware of two things:

- **Publishing pins a snapshot.** `mergeConfigFrom` is a *shallow, top-level* merge, so
  your published `supervisor` (or any) block **replaces** the package's entirely. Keys
  the package adds in a later release won't exist in your copy and fall back to the
  in-code default. **If you publish, re-run `vendor:publish --tag=jobwarden-config
  --force` after upgrading** — or simply don't publish and drive everything by env.
- **`config:cache` freezes `env()`.** In production, `php artisan config:cache` reads
  env **once**, at cache time. Set your vars *before* caching and re-cache after
  changing them. (The JobWarden container image clears its baked config cache on
  startup precisely so runtime `JOBWARDEN_*` overrides still apply — see HOSTING.)

### The one thing that is NOT config: the auth gate

The API/dashboard authorization gate is set in code, not env — it defaults to
local-environment-only (like Horizon):

```php
use JobWarden\JobWarden;

JobWarden::auth(fn ($request) => $request->user()?->can('viewJobWarden') ?? false);
```

---

## Reference

### Connection & schema

| Env var | Default | Effect |
|---|---|---|
| `JOBWARDEN_CONNECTION` | `jobwarden` | The dedicated DB connection the whole engine resolves its handle from. Give it its own connection/user (see HOSTING → connection isolation). |
| `JOBWARDEN_TABLE_PREFIX` | `jobwarden_` | Prefix for all engine tables. |
| `JOBWARDEN_ID_STRATEGY` | `uuid7` | Primary-key strategy: `uuid7` (sortable, distributed). `snowflake` is deferred. |

### Claiming

| Env var | Default | Effect |
|---|---|---|
| `JOBWARDEN_CLAIM_DRIVER` | `auto` | `auto` = SKIP LOCKED where the engine supports it, else the optimistic guarded-UPDATE fallback. Force with `skip_locked` / `optimistic`. |
| `JOBWARDEN_CLAIM_BATCH_SIZE` | `1` | Rows a single claim query locks (advanced; the supervisor claims up to its free slots). |

### Concurrency & the adaptive poll cadence

| Env var | Default | Effect |
|---|---|---|
| `JOBWARDEN_CAPACITY` | `5` | Concurrent jobs per supervisor (default lane). *(The container image's launcher defaults this to 6 when unset.)* |
| `JOBWARDEN_EXECUTION_MODE` | `child` | `child` = a fresh `php` process per job (full isolation, ~144ms boot each). `prefork` = fork the already-booted supervisor per job (same isolation, no boot; needs `pcntl`). See [HOSTING → Execution model](HOSTING.md#execution-model-child-vs-prefork). |
| `JOBWARDEN_PREFORK_RECYCLE_AFTER` | `50000` | `prefork` only — forks before the master drains + restarts for a fresh copy-on-write baseline. `0` disables. |
| `JOBWARDEN_GRACEFUL_TIMEOUT` | `10` | Seconds a stop waits (SIGTERM → SIGKILL) for a cancelled child before force-killing it. |
| `JOBWARDEN_DRAIN_TIMEOUT` | `0` | Seconds a supervisor waits for in-flight children on SIGTERM. `0` = wait indefinitely (recommended when the orchestrator protects busy tasks). |
| `JOBWARDEN_BUNDLE_REAPER` | `true` | Whether `jobwarden:work` bundles its own Tier-2 local reaper. `false` only for advanced splits that run `jobwarden:reap:local` separately. |

**Poll cadence is adaptive.** The supervisor senses demand from each claim's *fill
ratio* (how many of its free slots it actually filled) and adjusts the loop sleep, so it
stays responsive under load without hammering the DB when idle:

| Env var | Default | Rung |
|---|---|---|
| `JOBWARDEN_POLL_MIN_MS` | `50` | **Floor** — all slots busy, or two consecutive full-capacity claims (sustained demand). |
| `JOBWARDEN_POLL_INTERVAL_MS` | `500` | One full-capacity claim; also the base/"active" rung. *(The partial-fill rung is derived as half the idle ceiling.)* |
| `JOBWARDEN_POLL_IDLE_MS` | `5000` | **Ceiling** — a claim returned nothing (dry queue); back off so an idle fleet is quiet. |

Setting `JOBWARDEN_POLL_INTERVAL_MS` alone still works — it moves the middle rung; the
floor/ceiling bound the ramp.

### Retry, backoff & idempotency

| Env var | Default | Effect |
|---|---|---|
| `JOBWARDEN_MAX_ATTEMPTS` | `1` | Default attempts before a job is terminal (per-job `max_attempts` overrides). |
| `JOBWARDEN_BACKOFF_STRATEGY` | `exponential` | Retry backoff shape (`exponential` / `fixed`). |
| `JOBWARDEN_BACKOFF_BASE` | `5` | Base backoff seconds. |
| `JOBWARDEN_BACKOFF_CAP` | `300` | Max backoff seconds. |
| `JOBWARDEN_NONIDEMPOTENT_ORPHAN` | `park` | What to do with a **non-idempotent** orphan: `park` (recommended — hold for an operator) or `auto_fail`. Idempotent orphans always auto-recover. |

### Recovery: host lease & reapers

Recovery latency ≈ `HEARTBEAT_INTERVAL × MISSED_BEATS` (default ~30s) — the only timeout
in the system.

| Env var | Default | Effect |
|---|---|---|
| `JOBWARDEN_HEARTBEAT_INTERVAL` | `10` | Seconds between worker lease heartbeats. |
| `JOBWARDEN_MISSED_BEATS` | `3` | Missed beats before a worker is declared dead (`interval × beats` = detection budget). |
| `JOBWARDEN_LOCAL_SCAN_INTERVAL` | `5` | Tier-2 local reaper scan cadence (seconds). |
| `JOBWARDEN_LOCAL_LEASE_TTL` | `15` | Per-host lease TTL electing the single active local reaper (seconds). |
| `JOBWARDEN_SELF_FENCE_TTL` | `25` | When a local reaper kills its own children on lost DB connectivity (seconds). |
| `JOBWARDEN_GLOBAL_LEASE_TTL` | `15` | Tier-3 global-reaper leader-lease TTL = failover time (seconds). |
| `JOBWARDEN_GLOBAL_SCAN_INTERVAL` | `5` | Tier-3 scan cadence (seconds). |
| `JOBWARDEN_RECONCILE_GRACE` | `30` | Leader-only backstop: heal a job stuck in `running` whose attempt already settled, only after this many seconds (so a healthy worker mid-completion is never raced). |

### Stuck detection

| Env var | Default | Effect |
|---|---|---|
| `JOBWARDEN_MAX_RUNTIME_SEC` | *(unset)* | Flag a child that is alive + verified but has run longer than this. Never auto-reaped — surfaced for an operator. |

### Scheduler

| Env var | Default | Effect |
|---|---|---|
| `JOBWARDEN_TICK_INTERVAL` | `5` | Scheduler tick cadence (seconds). |
| `JOBWARDEN_MISSED_POLICY` | `run_latest` | On catch-up after downtime: `run_latest` vs run each missed occurrence. |
| `JOBWARDEN_OVERLAP_POLICY` | `skip` | If the prior run of a schedule is still going: `skip` the new one (vs allow overlap). |

### Logs & retention

| Env var | Default | Effect |
|---|---|---|
| `JOBWARDEN_LOG_SINK` | `database` | Where per-job log bodies go (`database` — pluggable `LogBodySink`). |
| `JOBWARDEN_LOG_RETENTION_DAYS` | `30` | Days to keep job logs. |
| `JOBWARDEN_LOG_BUFFER_SIZE` | `50` | In-child log buffer before flush. |
| `JOBWARDEN_RETENTION_JOBS_DAYS` | `30` | `jobwarden:prune` — delete terminal jobs (+ their attempts/events/logs/artifacts) older than this. |
| `JOBWARDEN_RETENTION_WORKERS_DAYS` | `7` | Prune dead/stopped worker rows older than this. |
| `JOBWARDEN_RETENTION_SCHEDULE_RUNS_DAYS` | `90` | Prune schedule-run history older than this. |

### Process & runtime

| Env var | Default | Effect |
|---|---|---|
| `JOBWARDEN_PROBE` | `auto` | Liveness probe: `auto` (detect OS) / `linux` (`/proc` + machine-id) / `fake` (tests). Production is Linux. |
| `JOBWARDEN_RUNTIME_PATH` | `storage_path('jobwarden')` | Where pidfiles and per-attempt logs live. |

### Operator API & dashboard

Both mount automatically and sit behind the same `JobWarden::auth()` gate (see above).
**Never expose the ungated dashboard publicly.**

| Env var | Default | Effect |
|---|---|---|
| `JOBWARDEN_API_ENABLED` | `true` | Mount the JSON operator API. |
| `JOBWARDEN_API_PREFIX` | `jobwarden/api` | API route prefix. |
| `JOBWARDEN_API_PER_PAGE` | `50` | API pagination size. |
| `JOBWARDEN_DASHBOARD_ENABLED` | `true` | Mount the Livewire dashboard. |
| `JOBWARDEN_DASHBOARD_PREFIX` | `jobwarden` | Dashboard route prefix. |
| `JOBWARDEN_DASHBOARD_POLL` | `5s` | Dashboard live-refresh interval. |

### Redis (optional — never required for correctness)

| Env var | Default | Effect |
|---|---|---|
| `JOBWARDEN_REDIS_ENABLED` | `false` | Enable optional Redis signaling (a latency optimization; the DB remains the source of truth). |
| `JOBWARDEN_REDIS_CONNECTION` | `default` | Which Redis connection to use when enabled. |
