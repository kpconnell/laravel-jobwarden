<?php

declare(strict_types=1);

namespace JobWarden\Batch;

use JobWarden\Models\Batch;
use JobWarden\Models\Job;
use JobWarden\States\BatchState;
use JobWarden\States\JobState;
use JobWarden\Support\SqlTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds a batch and its member jobs + DAG edges in one transaction (spec §8).
 * Topologies fall out of how dependsOn is used: none → fan-out; a single chain →
 * chain; arbitrary edges → DAG. A member with no deps starts `queued` (eligible
 * now); one with deps starts `pending` and is admitted by the Admitter when all
 * its deps have succeeded (the DepsSatisfiedGuard).
 */
final class BatchBuilder
{
    /** @var array<string, array{jobClass:string, params:array, dependsOn:string[], options:array}> */
    private array $specs = [];

    public function __construct(
        private readonly string $name,
        private readonly string $failurePolicy = 'continue',
        private readonly ?int $failureThreshold = null,
        private readonly string $type = 'batch',
    ) {
    }

    /**
     * @param  string[]  $dependsOn  local keys of jobs that must succeed first
     * @param  array<string,mixed>  $options  idempotent, max_attempts, priority,
     *                                         available_at (delay/stagger), name,
     *                                         backoff_strategy, …
     */
    public function add(string $key, string $jobClass, array $params = [], array $dependsOn = [], array $options = []): self
    {
        $this->specs[$key] = compact('jobClass', 'params', 'dependsOn', 'options');

        return $this;
    }

    public function dispatch(): Batch
    {
        $this->assertAcyclic();

        $conn = DB::connection(config('jobwarden.connection'));
        $prefix = (string) config('jobwarden.table_prefix');

        return $conn->transaction(function () use ($conn, $prefix): Batch {
            $batch = Batch::create([
                'name' => $this->name,
                'type' => $this->type,
                'state' => BatchState::Running,
                'failure_policy' => $this->failurePolicy,
                'failure_threshold' => $this->failureThreshold,
                'params' => null,
                'total_jobs' => count($this->specs),
                'pending_count' => count($this->specs),
            ]);
            // started_at on the DB clock — the batch is Running from creation. Eloquent's
            // datetime cast can't carry a raw CURRENT_TIMESTAMP, so stamp it via the query
            // builder like every other JobWarden time column (see JobWarden::dispatch()).
            $conn->table($batch->getTable())
                ->where($batch->getKeyName(), $batch->getKey())
                ->update(['started_at' => $conn->raw(SqlTime::nowExpr($conn))]);

            $ids = [];
            $dbNow = SqlTime::now($conn); // the DB clock, once, for eligibility math
            foreach ($this->specs as $key => $spec) {
                // A member is claimable immediately only if it has no deps AND its
                // available_at is due. A future available_at (per-member delay /
                // stagger, e.g. one chain root per minute to spare an external
                // API) starts it `pending`; the admit pass promotes it once due —
                // the same rule JobWarden::dispatch() applies. Pending/Queued share
                // the pending_count bucket, so the starting state is counter-neutral.
                // Delay is measured against the DB clock so available_at lands in the DB
                // timezone frame (the claim/admit compare it against CURRENT_TIMESTAMP).
                $delaySeconds = isset($spec['options']['available_at'])
                    ? (int) ceil($dbNow->diffInSeconds(Carbon::parse($spec['options']['available_at']), false))
                    : 0;
                $eligible = $spec['dependsOn'] === [] && $delaySeconds <= 0;
                $job = Job::create([
                    'batch_id' => $batch->id,
                    'job_class' => $spec['jobClass'],
                    'name' => $spec['options']['name'] ?? $key,
                    'params' => $spec['params'],
                    'idempotent' => (bool) ($spec['options']['idempotent'] ?? false),
                    'priority' => (int) ($spec['options']['priority'] ?? 0),
                    'state' => $eligible ? JobState::Queued : JobState::Pending,
                    'max_attempts' => (int) ($spec['options']['max_attempts'] ?? config('jobwarden.retry.max_attempts', 4)),
                    'attempt_count' => 0,
                    'backoff_strategy' => $spec['options']['backoff_strategy'] ?? config('jobwarden.retry.backoff.strategy'),
                ]);
                // available_at/queued_at from the DB clock (see JobWarden::dispatch()).
                $conn->table($job->getTable())
                    ->where($job->getKeyName(), $job->getKey())
                    ->update([
                        'available_at' => $conn->raw($delaySeconds > 0 ? SqlTime::nowPlus($conn, $delaySeconds) : SqlTime::nowExpr($conn)),
                        'queued_at' => $eligible ? $conn->raw(SqlTime::nowExpr($conn)) : null,
                    ]);
                $ids[$key] = $job->id;
            }

            foreach ($this->specs as $key => $spec) {
                foreach ($spec['dependsOn'] as $depKey) {
                    if (! isset($ids[$depKey])) {
                        throw new RuntimeException("batch '{$this->name}': job '{$key}' depends on unknown '{$depKey}'");
                    }
                    $conn->table($prefix.'job_dependencies')->insert([
                        'job_id' => $ids[$key],
                        'depends_on_job_id' => $ids[$depKey],
                    ]);
                }
            }

            return $batch;
        });
    }

    /** Kahn's algorithm: a DAG must have a topological order; a cycle would hang. */
    private function assertAcyclic(): void
    {
        $indegree = [];
        $edges = [];
        foreach ($this->specs as $key => $spec) {
            $indegree[$key] ??= 0;
            foreach ($spec['dependsOn'] as $dep) {
                $edges[$dep][] = $key;
                $indegree[$key] = ($indegree[$key] ?? 0) + 1;
            }
        }

        $queue = array_keys(array_filter($indegree, fn ($d) => $d === 0));
        $visited = 0;
        while ($queue !== []) {
            $node = array_shift($queue);
            $visited++;
            foreach ($edges[$node] ?? [] as $next) {
                if (--$indegree[$next] === 0) {
                    $queue[] = $next;
                }
            }
        }

        if ($visited !== count($this->specs)) {
            throw new RuntimeException("batch '{$this->name}' has a dependency cycle");
        }
    }
}
