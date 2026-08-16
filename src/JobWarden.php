<?php

declare(strict_types=1);

namespace JobWarden;

use JobWarden\Batch\BatchBuilder;
use JobWarden\Batch\BatchCoordinator;
use JobWarden\Jobs\RunArtisanCommand;
use JobWarden\Models\Batch;
use JobWarden\Models\Job;
use JobWarden\Models\Schedule;
use JobWarden\Search\TagWriter;
use JobWarden\States\JobState;
use JobWarden\Support\SqlTime;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The entry-point service for putting work into JobWarden. It does NOT hijack
 * Laravel's dispatch() — jobs opt in explicitly (spec §0 non-goals).
 */
class JobWarden
{
    /** Authorization gate for the operator API/dashboard (Horizon-style). */
    private static ?Closure $authUsing = null;

    public function __construct(private readonly BatchCoordinator $batchCoordinator)
    {
    }

    /**
     * Register the callback that authorizes operator-API/dashboard requests.
     * Call this from a service provider: JobWarden::auth(fn ($request) => ...).
     */
    public static function auth(Closure $callback): void
    {
        self::$authUsing = $callback;
    }

    /** Is this request allowed to use the operator API? Defaults to local only. */
    public static function check($request): bool
    {
        return (bool) (self::$authUsing ?? static fn ($request): bool => app()->environment('local'))($request);
    }

    /** Start building a batch (fan-out / chain / DAG) — see BatchBuilder. */
    public function batch(string $name, string $failurePolicy = 'continue', ?int $failureThreshold = null, string $type = 'batch'): BatchBuilder
    {
        return new BatchBuilder($name, $failurePolicy, $failureThreshold, $type, $this->batchCoordinator);
    }

    /** Cancel a batch — propagates to every non-terminal member (spec §8.3). */
    public function cancelBatch(Batch $batch, string $reason = 'operator cancel', ?string $actorId = null): void
    {
        $this->batchCoordinator->cancel($batch, $reason, $actorId);
    }

    /**
     * Register a recurring schedule (cron). Pass `__idempotent => true` in params
     * to make the scheduled jobs auto-retry. See config for default policies.
     *
     * @param  array<string,mixed>  $params
     * @param  array<string,mixed>  $options  timezone, enabled, missed_policy,
     *                                         overlap_policy, catch_up_window_sec,
     *                                         max_catch_up, priority, owner
     */
    public function schedule(string $name, string $cron, string $jobClass, array $params = [], array $options = []): Schedule
    {
        // Fail fast: reject an invalid cron when it's registered, not silently at
        // evaluation time (where it would just be logged and skipped forever).
        if (! \Cron\CronExpression::isValidExpression($cron)) {
            throw new \InvalidArgumentException("Invalid cron expression: [{$cron}]");
        }

        return Schedule::create([
            'name' => $name,
            'job_class' => $jobClass,
            'params' => $params,
            'kind' => 'recurring',
            'cron_expression' => $cron,
            'timezone' => $options['timezone'] ?? 'UTC',
            'enabled' => $options['enabled'] ?? true,
            'idempotent' => (bool) ($options['idempotent'] ?? false),
            'max_attempts' => $options['max_attempts'] ?? null,
            'missed_policy' => $options['missed_policy'] ?? config('jobwarden.scheduler.missed_policy', 'run_latest'),
            'overlap_policy' => $options['overlap_policy'] ?? config('jobwarden.scheduler.overlap_policy', 'skip'),
            'catch_up_window_sec' => $options['catch_up_window_sec'] ?? null,
            'max_catch_up' => $options['max_catch_up'] ?? null,
            'priority' => (int) ($options['priority'] ?? 0),
            'owner' => $options['owner'] ?? null,
        ]);
    }

    /**
     * Register a recurring schedule that runs an artisan command. The command
     * runs on the dedicated scheduled-tier runner (the `scheduled` lane) with the
     * full machinery — child process, reaper recovery, output captured to the job
     * log.
     *
     * By default a command is single-shot: if its host dies mid-run the outcome is
     * indeterminate, so it PARKS for an operator. Declare `idempotent => true` (in
     * $options) when the command is safe to re-run, and a lost run is retried on
     * another host instead (with a `max_attempts` retry budget).
     *
     * @param  array<string,mixed>  $arguments  artisan arguments/options
     * @param  array<string,mixed>  $options    idempotent, max_attempts, timezone, missed_policy, …
     */
    public function scheduleCommand(string $name, string $cron, string $command, array $arguments = [], array $options = []): Schedule
    {
        return $this->schedule(
            $name,
            $cron,
            RunArtisanCommand::class,
            ['command' => $command, 'arguments' => $arguments],
            $options,
        );
    }

    /** Dispatch a one-off artisan command run onto the scheduled lane. */
    public function dispatchCommand(string $command, array $arguments = [], array $options = []): Job
    {
        $idempotent = (bool) ($options['idempotent'] ?? false);
        $options['lane'] = 'scheduled';
        $options['idempotent'] = $idempotent;
        $options['max_attempts'] = $options['max_attempts'] ?? ($idempotent ? 3 : 1);

        return $this->dispatch(RunArtisanCommand::class, ['command' => $command, 'arguments' => $arguments], $options);
    }

    /** Register a one-time schedule that fires once at $runAt. */
    public function scheduleOnce(string $name, Carbon $runAt, string $jobClass, array $params = [], array $options = []): Schedule
    {
        $conn = DB::connection(config('jobwarden.connection'));

        // run_at gates the one-time fire (`run_at <= now`) and the evaluator reads it back
        // off the DB clock, so it must land in the DB's frame — a bound Carbon loses its
        // offset and stores app-local wall-clock. Same offset-from-now conversion dispatch()
        // uses for a caller-supplied available_at, and stamped in the same transaction so a
        // concurrent scheduler tick never sees a one_time schedule with a null run_at.
        $delaySeconds = (int) ceil(SqlTime::now($conn)->diffInSeconds($runAt, false));

        return $conn->transaction(function () use ($conn, $name, $jobClass, $params, $options, $delaySeconds): Schedule {
            $schedule = Schedule::create([
                'name' => $name,
                'job_class' => $jobClass,
                'params' => $params,
                'kind' => 'one_time',
                'timezone' => $options['timezone'] ?? 'UTC',
                'enabled' => $options['enabled'] ?? true,
                'idempotent' => (bool) ($options['idempotent'] ?? false),
                'max_attempts' => $options['max_attempts'] ?? null,
                'missed_policy' => 'run_latest',
                'overlap_policy' => $options['overlap_policy'] ?? 'allow',
                'priority' => (int) ($options['priority'] ?? 0),
            ]);

            $conn->table($schedule->getTable())
                ->where($schedule->getKeyName(), $schedule->getKey())
                ->update(['run_at' => $conn->raw(SqlTime::nowPlus($conn, $delaySeconds))]);

            return $schedule->refresh();
        });
    }

    /**
     * Create a job. Eligible immediately → `queued`; gated by a future
     * available_at or a cross-batch dependency → `pending` (the admit pass
     * promotes it when due/satisfied).
     *
     * @param  array<string,mixed>  $params
     * @param  array<string,mixed>  $options  idempotent, max_attempts, priority,
     *                                         delay (seconds), available_at,
     *                                         backoff_strategy, max_runtime_sec,
     *                                         name, idempotency_key, tags,
     *                                         batch_id, created_by,
     *                                         depends_on_batches,
     *                                         depends_on_batch_completion
     */
    public function dispatch(string $jobClass, array $params = [], array $options = []): Job
    {
        // Validate explicit tags BEFORE the transaction — a bad tag is the
        // dispatcher's bug and must fail loudly at the dispatch site.
        $tags = TagWriter::assertValid($options['tags'] ?? null);

        // Cross-batch deps: `depends_on_batches` requires each upstream batch to
        // reach `succeeded` (strictly — partial dooms); `depends_on_batch_completion`
        // requires terminal AND quiescent. Same one-edge-one-condition rule as
        // BatchBuilder. Ids are validated inside the transaction below.
        // A bare Batch model is wrapped rather than (array)-cast — the cast would
        // iterate its property bag and yield garbage ids.
        $successRefs = $options['depends_on_batches'] ?? [];
        $completionRefs = $options['depends_on_batch_completion'] ?? [];
        $batchDeps = [];
        foreach (($successRefs instanceof Batch ? [$successRefs] : (array) $successRefs) as $ref) {
            $batchDeps[$ref instanceof Batch ? (string) $ref->id : (string) $ref] = 'on_success';
        }
        foreach (($completionRefs instanceof Batch ? [$completionRefs] : (array) $completionRefs) as $ref) {
            $id = $ref instanceof Batch ? (string) $ref->id : (string) $ref;
            if (($batchDeps[$id] ?? null) === 'on_success') {
                throw new \InvalidArgumentException(
                    "Batch '{$id}' listed under both depends_on_batches and depends_on_batch_completion"
                    .' — an edge carries one condition.'
                );
            }
            $batchDeps[$id] = 'on_completion';
        }
        if (isset($options['batch_id'], $batchDeps[(string) $options['batch_id']])) {
            throw new \InvalidArgumentException('A job cannot depend on the batch it is dispatched into.');
        }

        $conn = DB::connection(config('jobwarden.connection'));

        // Delay is measured against the DB clock, so available_at lands in the DB's
        // timezone frame regardless of app.timezone — the claim/admit compare it against
        // CURRENT_TIMESTAMP. A 'delay' in seconds is already an offset from "now" and
        // feeds nowPlus() directly, no clock read needed; a user-supplied available_at
        // is an absolute instant, so its offset from "now" is timezone-agnostic.
        $delaySeconds = match (true) {
            isset($options['delay']) => (int) $options['delay'],
            isset($options['available_at']) => (int) ceil(SqlTime::now($conn)->diffInSeconds(Carbon::parse($options['available_at']), false)),
            default => 0,
        };
        // Batch deps gate eligibility even when every upstream already succeeded
        // — one admit-tick of latency beats a TOCTOU check (see BatchBuilder).
        $eligible = $delaySeconds <= 0 && $batchDeps === [];

        $job = $conn->transaction(function () use ($conn, $jobClass, $params, $options, $tags, $eligible, $delaySeconds, $batchDeps): Job {
            // Validate the referenced batches BEFORE creating anything — an
            // unknown id is the dispatcher's bug and must fail with no row.
            $prefix = (string) config('jobwarden.table_prefix');
            if ($batchDeps !== []) {
                $known = $conn->table($prefix.'batches')
                    ->whereIn('id', array_keys($batchDeps))
                    ->pluck('id')
                    ->map(static fn ($id): string => (string) $id)
                    ->all();
                $missing = array_diff(array_keys($batchDeps), $known);
                if ($missing !== []) {
                    throw new \InvalidArgumentException(
                        "Job depends on unknown batch '".implode("', '", $missing)."'."
                    );
                }
            }

            $job = Job::create([
                'job_class' => $jobClass,
                'name' => $options['name'] ?? null,
                'lane' => $options['lane'] ?? 'default',
                'params' => $params,
                'idempotent' => (bool) ($options['idempotent'] ?? false),
                'idempotency_key' => $options['idempotency_key'] ?? null,
                'priority' => (int) ($options['priority'] ?? 0),
                'state' => $eligible ? JobState::Queued : JobState::Pending,
                'max_attempts' => (int) ($options['max_attempts'] ?? config('jobwarden.retry.max_attempts', 4)),
                'attempt_count' => 0,
                'max_runtime_sec' => $options['max_runtime_sec'] ?? config('jobwarden.stuck.max_runtime_sec'),
                'backoff_strategy' => $options['backoff_strategy'] ?? config('jobwarden.retry.backoff.strategy'),
                'batch_id' => $options['batch_id'] ?? null,
                'schedule_id' => $options['schedule_id'] ?? null,
                'created_by' => $options['created_by'] ?? null,
            ]);

            TagWriter::write($job, $tags);

            foreach ($batchDeps as $batchId => $condition) {
                $conn->table($prefix.'job_batch_dependencies')->insert([
                    'job_id' => $job->id,
                    'depends_on_batch_id' => $batchId,
                    'edge_condition' => $condition,
                ]);
            }

            // Stamp the DB-clock timestamps via the query builder (Eloquent's datetime cast
            // rejects a raw CURRENT_TIMESTAMP, and a stored Carbon would be re-serialized in
            // the app timezone). available_at = now (or now + delay); queued_at = now if it
            // went straight to the queue.
            $conn->table($job->getTable())
                ->where($job->getKeyName(), $job->getKey())
                ->update([
                    'available_at' => $conn->raw($eligible ? SqlTime::nowExpr($conn) : SqlTime::nowPlus($conn, $delaySeconds)),
                    'queued_at' => $eligible ? $conn->raw(SqlTime::nowExpr($conn)) : null,
                ]);

            return $job->refresh();
        });

        // Ergonomic fast path, NOT load-bearing: an already-doomed upstream
        // cancels this job now instead of on the reaper's next stranded sweep —
        // which remains the correctness guarantee for any race this read loses.
        foreach (array_keys($batchDeps) as $upstreamId) {
            $upstream = Batch::find($upstreamId);
            if ($upstream !== null) {
                $this->batchCoordinator->cancelBatchDependents($upstream);
            }
        }

        return $job;
    }
}
