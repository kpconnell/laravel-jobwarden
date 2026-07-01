<?php

declare(strict_types=1);

namespace JobWarden;

use JobWarden\Batch\BatchBuilder;
use JobWarden\Batch\BatchCoordinator;
use JobWarden\Jobs\RunArtisanCommand;
use JobWarden\Models\Batch;
use JobWarden\Models\Job;
use JobWarden\Models\Schedule;
use JobWarden\States\JobState;
use Closure;
use Illuminate\Support\Carbon;

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
        return new BatchBuilder($name, $failurePolicy, $failureThreshold, $type);
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
        return Schedule::create([
            'name' => $name,
            'job_class' => $jobClass,
            'params' => $params,
            'kind' => 'one_time',
            'run_at' => $runAt,
            'timezone' => $options['timezone'] ?? 'UTC',
            'enabled' => $options['enabled'] ?? true,
            'idempotent' => (bool) ($options['idempotent'] ?? false),
            'max_attempts' => $options['max_attempts'] ?? null,
            'missed_policy' => 'run_latest',
            'overlap_policy' => $options['overlap_policy'] ?? 'allow',
            'priority' => (int) ($options['priority'] ?? 0),
        ]);
    }

    /**
     * Create a job. Eligible immediately → `queued`; gated by a future
     * available_at → `pending` (the admit pass promotes it when due).
     *
     * @param  array<string,mixed>  $params
     * @param  array<string,mixed>  $options  idempotent, max_attempts, priority,
     *                                         available_at, backoff_strategy,
     *                                         max_runtime_sec, name, idempotency_key,
     *                                         tags, batch_id, created_by
     */
    public function dispatch(string $jobClass, array $params = [], array $options = []): Job
    {
        $availableAt = $options['available_at'] ?? Carbon::now();
        $eligible = Carbon::parse($availableAt)->lessThanOrEqualTo(Carbon::now());

        return Job::create([
            'job_class' => $jobClass,
            'name' => $options['name'] ?? null,
            'lane' => $options['lane'] ?? 'default',
            'params' => $params,
            'idempotent' => (bool) ($options['idempotent'] ?? false),
            'idempotency_key' => $options['idempotency_key'] ?? null,
            'priority' => (int) ($options['priority'] ?? 0),
            'state' => $eligible ? JobState::Queued : JobState::Pending,
            'available_at' => $availableAt,
            'max_attempts' => (int) ($options['max_attempts'] ?? config('jobwarden.retry.max_attempts', 1)),
            'attempt_count' => 0,
            'max_runtime_sec' => $options['max_runtime_sec'] ?? config('jobwarden.stuck.max_runtime_sec'),
            'backoff_strategy' => $options['backoff_strategy'] ?? config('jobwarden.retry.backoff.strategy'),
            'tags' => $options['tags'] ?? null,
            'batch_id' => $options['batch_id'] ?? null,
            'schedule_id' => $options['schedule_id'] ?? null,
            'created_by' => $options['created_by'] ?? null,
            'queued_at' => $eligible ? Carbon::now() : null,
        ]);
    }
}
