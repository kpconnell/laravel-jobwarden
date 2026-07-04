<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Dedicated connection
    |--------------------------------------------------------------------------
    | JobWarden runs on its own DB connection so the engine can live on a
    | separate database/replica topology, independent of the app's default
    | connection. EVERYTHING in the engine resolves its handle from here.
    */
    'connection' => env('JOBWARDEN_CONNECTION', 'jobwarden'),

    'table_prefix' => env('JOBWARDEN_TABLE_PREFIX', 'jobwarden_'),

    /*
    |--------------------------------------------------------------------------
    | Primary key strategy
    |--------------------------------------------------------------------------
    | uuid7 (default, sortable, distributed) or snowflake (deferred).
    */
    'id' => [
        'strategy' => env('JOBWARDEN_ID_STRATEGY', 'uuid7'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Claiming
    |--------------------------------------------------------------------------
    | auto       => detect engine; SKIP LOCKED where supported, else optimistic.
    | skip_locked => force SELECT ... FOR UPDATE SKIP LOCKED.
    | optimistic  => force the guarded-UPDATE fallback.
    */
    'claim' => [
        'driver' => env('JOBWARDEN_CLAIM_DRIVER', 'auto'),
        'batch_size' => (int) env('JOBWARDEN_CLAIM_BATCH_SIZE', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Host lease (the ONE coarse heartbeat; the only timeout in the system)
    |--------------------------------------------------------------------------
    | heartbeat_interval * missed_beats = host-down detection budget (~40s).
    | Refreshed by the local reaper against the DB clock, never per job.
    */
    'host_lease' => [
        'heartbeat_interval' => (int) env('JOBWARDEN_HEARTBEAT_INTERVAL', 10),
        'missed_beats' => (int) env('JOBWARDEN_MISSED_BEATS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reapers
    |--------------------------------------------------------------------------
    */
    'reaper' => [
        // Tier 2 stamp-verification cadence (seconds).
        'local_scan_interval' => (int) env('JOBWARDEN_LOCAL_SCAN_INTERVAL', 5),
        // Exactly ONE local reaper scans per host; any others (e.g. a host running
        // both jobwarden:work and jobwarden:scheduled-worker, each bundling one)
        // idle as hot spares. This is the per-host lease TTL (seconds) electing it.
        'local_lease_ttl' => (int) env('JOBWARDEN_LOCAL_LEASE_TTL', 15),
        // When a local reaper kills its own children on lost connectivity (seconds).
        'self_fence_ttl' => (int) env('JOBWARDEN_SELF_FENCE_TTL', 25),
        // Tier 3 leader-lease TTL (seconds).
        'global_lease_ttl' => (int) env('JOBWARDEN_GLOBAL_LEASE_TTL', 15),
        'global_scan_interval' => (int) env('JOBWARDEN_GLOBAL_SCAN_INTERVAL', 5),
        // Reconciliation backstop (leader-only): heal a job stuck in `running`
        // whose current attempt already settled — the residue of a process dying
        // between the attempt and job transitions. Only jobs whose attempt has
        // been settled this many seconds are touched, so a healthy worker
        // mid-completion is never raced. Fencing already prevents any double-run.
        'reconcile_grace_sec' => (int) env('JOBWARDEN_RECONCILE_GRACE', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Supervisor / execution
    |--------------------------------------------------------------------------
    */
    'supervisor' => [
        'capacity' => (int) env('JOBWARDEN_CAPACITY', 5),
        // jobwarden:work bundles a co-resident local reaper (as a separate child
        // process) by default, so a worker can never run without Tier-2 recovery.
        // Set false only for advanced split topologies where you run
        // jobwarden:reap:local as its own supervised process.
        'bundle_reaper' => filter_var(env('JOBWARDEN_BUNDLE_REAPER', true), FILTER_VALIDATE_BOOLEAN),
        // How each claimed job runs:
        //   child   (default) — proc_open a fresh `php artisan jobwarden:run` per job:
        //             maximal isolation, but a ~144ms framework boot on every job.
        //   prefork — pcntl_fork() from the already-booted supervisor per job: the SAME
        //             per-job PID isolation (crash/leak contained, waitpid-reaped, clean
        //             COW slate) minus the boot. Requires the pcntl extension; falls back
        //             to 'child' where it is unavailable.
        //   in_process (deferred).
        'execution_mode' => env('JOBWARDEN_EXECUTION_MODE', 'child'),
        'graceful_timeout' => (int) env('JOBWARDEN_GRACEFUL_TIMEOUT', 10),
        'poll_interval_ms' => (int) env('JOBWARDEN_POLL_INTERVAL_MS', 500),
        // Adaptive poll cadence: the supervisor senses demand from each claim's fill ratio
        // (how many of its free slots it actually filled) and adjusts the loop sleep between
        // poll_min_ms (sustained full demand — reap + refill hot) and poll_idle_ms (nothing
        // to claim — quiet, so an idle fleet does not hammer the DB with poll queries).
        // poll_interval_ms above is the "one full fill" rung; the partial rung is derived.
        'poll_min_ms' => (int) env('JOBWARDEN_POLL_MIN_MS', 50),
        'poll_idle_ms' => (int) env('JOBWARDEN_POLL_IDLE_MS', 5000),
        // PREFORK only: recycle the long-lived master after this many forks — it drains
        // its in-flight forks, exits, and the launcher restarts it with a fresh, pristine
        // COW baseline. This bounds any slow master-side memory growth that would
        // otherwise be inherited by every fork. 0 disables. Fires rarely at real
        // throughput (~every few minutes), well under the launcher's crash-loop threshold.
        'prefork_recycle_after' => (int) env('JOBWARDEN_PREFORK_RECYCLE_AFTER', 50000),

        // On SIGTERM the supervisor stops claiming and waits for in-flight
        // children to finish, up to this many seconds. 0 = wait indefinitely
        // (the recommended default when the orchestrator protects busy tasks,
        // e.g. ECS task scale-in protection, so long idempotent jobs complete).
        // A child that outlasts the timeout keeps running and self-reports; if
        // the container is then torn down, a reaper recovers it (idempotent → re-run).
        'drain_timeout' => (int) env('JOBWARDEN_DRAIN_TIMEOUT', 0),

        // A tick that fails on transient infrastructure (lost DB connection,
        // failover, deadlock) is absorbed and retried with exponential backoff
        // from this base (capped at 30s) instead of exiting — a dead supervisor
        // gets its healthy children killed by Tier-2, so a DB blip must not
        // become killed work. Deterministic failures (code/schema bugs, which
        // would recur every tick) still exit loudly after a few strikes.
        'tick_failure_backoff_ms' => (int) env('JOBWARDEN_TICK_FAILURE_BACKOFF_MS', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry / backoff / idempotency
    |--------------------------------------------------------------------------
    */
    'retry' => [
        // The retry budget only ever spends for IDEMPOTENT jobs (non-idempotent
        // jobs never retry — they fail on error and park on orphan regardless of
        // budget), so a >1 default is safe fleet-wide and means "idempotent"
        // actually buys recovery without each dispatch site granting a budget.
        'max_attempts' => (int) env('JOBWARDEN_MAX_ATTEMPTS', 4),
        'backoff' => [
            'strategy' => env('JOBWARDEN_BACKOFF_STRATEGY', 'exponential'),
            'base' => (int) env('JOBWARDEN_BACKOFF_BASE', 5),
            'cap' => (int) env('JOBWARDEN_BACKOFF_CAP', 300),
        ],
        // park (recommended) | auto_fail — what to do with a non-idempotent orphan.
        'non_idempotent_orphan_policy' => env('JOBWARDEN_NONIDEMPOTENT_ORPHAN', 'park'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stuck detection (alive + verified, but not advancing) — never auto-reaped
    |--------------------------------------------------------------------------
    */
    'stuck' => [
        'max_runtime_sec' => env('JOBWARDEN_MAX_RUNTIME_SEC') !== false
            ? (int) env('JOBWARDEN_MAX_RUNTIME_SEC')
            : null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Results (jobs.result — the completion payload)
    |--------------------------------------------------------------------------
    | A handler stores its completion payload via $context->result([...]); it
    | commits atomically with the succeeded transition. Bounded here so the hot
    | jobs table never accretes megabyte rows — anything bigger belongs in
    | job_artifacts, with the result carrying the artifact id.
    */
    'results' => [
        'max_bytes' => (int) env('JOBWARDEN_RESULT_MAX_BYTES', 65536),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduler
    |--------------------------------------------------------------------------
    */
    'scheduler' => [
        'tick_interval' => (int) env('JOBWARDEN_TICK_INTERVAL', 5),
        'missed_policy' => env('JOBWARDEN_MISSED_POLICY', 'run_latest'),
        'overlap_policy' => env('JOBWARDEN_OVERLAP_POLICY', 'skip'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logs (LogIndex + pluggable LogBodySink)
    |--------------------------------------------------------------------------
    */
    'logs' => [
        'sink' => env('JOBWARDEN_LOG_SINK', 'database'),
        'retention_days' => (int) env('JOBWARDEN_LOG_RETENTION_DAYS', 30),
        'buffer_size' => (int) env('JOBWARDEN_LOG_BUFFER_SIZE', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention / pruning (jobwarden:prune)
    |--------------------------------------------------------------------------
    | Terminal jobs older than `jobs_days` are deleted (cascading to their
    | attempts/events/logs/artifacts); dead/stopped worker rows older than
    | `workers_days` are removed.
    */
    'retention' => [
        'jobs_days' => (int) env('JOBWARDEN_RETENTION_JOBS_DAYS', 30),
        'workers_days' => (int) env('JOBWARDEN_RETENTION_WORKERS_DAYS', 7),
        'schedule_runs_days' => (int) env('JOBWARDEN_RETENTION_SCHEDULE_RUNS_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Search (job tags)
    |--------------------------------------------------------------------------
    | Jobs are searchable by tags (name → value pairs, values ≤ 200 chars).
    | Dispatchers pass tags explicitly; `promoted_params` additionally opts
    | constructor parameter NAMES in for automatic promotion — when a job's
    | params contain a listed name with a string value, it becomes a tag of
    | the same name. Non-string (or over-long) values are simply not promoted.
    | After changing this list, run `jobwarden:retag` to index existing jobs.
    */
    'search' => [
        'promoted_params' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('JOBWARDEN_PROMOTED_PARAMS', ''))),
            static fn (string $name): bool => $name !== '',
        )),
    ],

    /*
    |--------------------------------------------------------------------------
    | Process layer (liveness verification)
    |--------------------------------------------------------------------------
    | probe: auto (detect OS) | linux | fake (tests). The production target is
    | Linux (/proc start-times, machine-id + boot_id).
    */
    'process' => [
        'probe' => env('JOBWARDEN_PROBE', 'auto'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Runtime path (pidfiles, etc.)
    |--------------------------------------------------------------------------
    */
    'runtime_path' => env('JOBWARDEN_RUNTIME_PATH', storage_path('jobwarden')),

    /*
    |--------------------------------------------------------------------------
    | Optional Redis signaling (never required for correctness)
    |--------------------------------------------------------------------------
    */
    'redis' => [
        'enabled' => (bool) env('JOBWARDEN_REDIS_ENABLED', false),
        'connection' => env('JOBWARDEN_REDIS_CONNECTION', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Operator API (read + actions; foundation for the dashboard)
    |--------------------------------------------------------------------------
    | A gated JSON API over the read models and operator actions. Authorize
    | access with JobWarden::auth(fn ($request) => ...) — it defaults to local
    | environment only, exactly like Horizon's gate.
    */
    'api' => [
        'enabled' => (bool) env('JOBWARDEN_API_ENABLED', true),
        'prefix' => env('JOBWARDEN_API_PREFIX', 'jobwarden/api'),
        'middleware' => ['api'],
        'pagination' => (int) env('JOBWARDEN_API_PER_PAGE', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard (Livewire operator console)
    |--------------------------------------------------------------------------
    | Server-rendered, DB-polled. Same JobWarden::auth() gate as the API. Needs
    | the `web` middleware group (sessions/CSRF) for Livewire.
    */
    'dashboard' => [
        'enabled' => (bool) env('JOBWARDEN_DASHBOARD_ENABLED', true),
        'prefix' => env('JOBWARDEN_DASHBOARD_PREFIX', 'jobwarden'),
        'middleware' => ['web'],
        'poll' => env('JOBWARDEN_DASHBOARD_POLL', '5s'),
    ],

];
