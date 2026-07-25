<?php

declare(strict_types=1);

namespace JobWarden\Batch;

use JobWarden\Models\Batch;
use JobWarden\Models\Job;
use JobWarden\Search\TagWriter;
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
 * its deps are satisfied (the DepsSatisfiedGuard) — every dependency having
 * succeeded for a dependsOn edge, merely having ENDED for dependsOnCompletion.
 */
final class BatchBuilder
{
    /** @var array<string, array{jobClass:string, params:array, dependsOn:string[], dependsOnCompletion:string[], options:array}> */
    private array $specs = [];

    /** @var array<string, string> upstream batch id => on_success|on_completion */
    private array $batchDeps = [];

    public function __construct(
        private readonly string $name,
        private readonly string $failurePolicy = 'continue',
        private readonly ?int $failureThreshold = null,
        private readonly string $type = 'batch',
        private readonly ?BatchCoordinator $coordinator = null,
    ) {
    }

    /**
     * Gate every ROOT member on ALREADY-DISPATCHED upstream batches reaching
     * `succeeded` — strictly: `partial` dooms just like failed/canceled/stopped.
     * A doomed root is canceled as unreachable (and revived if the upstream
     * batch reopens); non-roots follow transitively through the intra-batch DAG,
     * so `finally` members still run. Cycles are impossible by construction —
     * an upstream must already exist when this batch is dispatched.
     *
     * @param  array<Batch|string>  $batches  batch models or ids
     */
    public function dependsOnBatches(array $batches): self
    {
        return $this->recordBatchDeps($batches, 'on_success');
    }

    /**
     * Cross-batch `finally`: gate the roots on the upstream batches merely
     * FINISHING — terminal whatever the verdict, and quiescent (a failed
     * upstream's spared finalizer subtree must drain first). Never doomed by
     * the upstream's outcome.
     *
     * @param  array<Batch|string>  $batches  batch models or ids
     */
    public function dependsOnBatchCompletion(array $batches): self
    {
        return $this->recordBatchDeps($batches, 'on_completion');
    }

    /** @param  array<Batch|string>  $batches */
    private function recordBatchDeps(array $batches, string $condition): self
    {
        foreach ($batches as $batch) {
            $id = $batch instanceof Batch ? (string) $batch->id : (string) $batch;
            // One edge, one condition — same rule as the member-level lists.
            if (($this->batchDeps[$id] ?? $condition) !== $condition) {
                throw new RuntimeException(
                    "batch '{$this->name}': upstream batch '{$id}' listed under both dependsOnBatches"
                    .' and dependsOnBatchCompletion — an edge carries one condition'
                );
            }
            $this->batchDeps[$id] = $condition;
        }

        return $this;
    }

    /**
     * @param  string[]  $dependsOn  local keys of jobs that must succeed first
     * @param  array<string,mixed>  $options  idempotent, max_attempts, priority,
     *                                         available_at (delay/stagger), name,
     *                                         backoff_strategy, tags, …
     * @param  string[]  $dependsOnCompletion  local keys of jobs that must merely
     *                                          END first, whatever their outcome —
     *                                          `finally` semantics for cleanup /
     *                                          reporting members. Such a member is
     *                                          NOT canceled as unreachable when its
     *                                          upstream fails, and an eager failure
     *                                          policy spares it (and its own tail).
     *                                          Trails $options to keep positional
     *                                          calls source-compatible.
     */
    public function add(string $key, string $jobClass, array $params = [], array $dependsOn = [], array $options = [], array $dependsOnCompletion = []): self
    {
        $this->specs[$key] = compact('jobClass', 'params', 'dependsOn', 'dependsOnCompletion', 'options');

        return $this;
    }

    public function dispatch(): Batch
    {
        // One edge, one condition — the edge row is keyed (job_id, depends_on_job_id),
        // so a key in both lists has no representable meaning (and would collide on
        // the primary key). Reject it at the dispatch site rather than half-writing.
        foreach ($this->specs as $key => $spec) {
            $both = array_intersect($spec['dependsOn'], $spec['dependsOnCompletion']);
            if ($both !== []) {
                throw new RuntimeException(
                    "batch '{$this->name}': job '{$key}' lists '".implode("', '", $both).
                    "' under both dependsOn and dependsOnCompletion — an edge carries one condition"
                );
            }
        }

        // A memberless batch has no roots to carry the edges — the declaration
        // would silently evaporate. Reject it rather than half-honoring it.
        if ($this->batchDeps !== [] && $this->specs === []) {
            throw new RuntimeException(
                "batch '{$this->name}': declares batch dependencies but has no members to gate"
            );
        }

        $this->assertAcyclic();

        // Validate every member's explicit tags BEFORE the transaction — a bad
        // tag fails the whole batch at the dispatch site, creating nothing.
        $tagsByKey = [];
        foreach ($this->specs as $key => $spec) {
            $tagsByKey[$key] = TagWriter::assertValid($spec['options']['tags'] ?? null);
        }

        $conn = DB::connection(config('jobwarden.connection'));
        $prefix = (string) config('jobwarden.table_prefix');

        $batch = $conn->transaction(function () use ($conn, $prefix, $tagsByKey): Batch {
            // Cross-batch deps reference batches that must already exist — an
            // unknown id is the dispatcher's bug and fails the whole dispatch,
            // creating nothing. An already-terminal upstream is fine: succeeded
            // reads as satisfied, doomed is handled after commit / by the sweep.
            if ($this->batchDeps !== []) {
                $known = $conn->table($prefix.'batches')
                    ->whereIn('id', array_keys($this->batchDeps))
                    ->pluck('id')
                    ->map(static fn ($id): string => (string) $id)
                    ->all();
                $missing = array_diff(array_keys($this->batchDeps), $known);
                if ($missing !== []) {
                    throw new RuntimeException(
                        "batch '{$this->name}': depends on unknown batch '".implode("', '", $missing)."'"
                    );
                }
            }

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
                // Batch deps gate the roots too — even against an upstream that
                // already succeeded (one admit-tick of latency beats a TOCTOU
                // check whose answer can be stale by commit time).
                $eligible = self::upstreams($spec) === [] && $delaySeconds <= 0 && $this->batchDeps === [];
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
                TagWriter::write($job, $tagsByKey[$key]);
                $ids[$key] = $job->id;
            }

            foreach ($this->specs as $key => $spec) {
                $edges = ['on_success' => $spec['dependsOn'], 'on_completion' => $spec['dependsOnCompletion']];
                foreach ($edges as $condition => $depKeys) {
                    foreach ($depKeys as $depKey) {
                        if (! isset($ids[$depKey])) {
                            throw new RuntimeException("batch '{$this->name}': job '{$key}' depends on unknown '{$depKey}'");
                        }
                        $conn->table($prefix.'job_dependencies')->insert([
                            'job_id' => $ids[$key],
                            'depends_on_job_id' => $ids[$depKey],
                            'edge_condition' => $condition,
                        ]);
                    }
                }
            }

            // Fan the batch-level deps down to the ROOTS only: a doomed upstream
            // batch cancels the roots, and the ordinary intra-batch cascade dooms
            // their on_success descendants while sparing `finally` members —
            // exactly as if the upstream batch were a virtual upstream of every
            // root. Fanning to ALL members would cancel the finalizers too and
            // break the finally contract.
            if ($this->batchDeps !== []) {
                foreach ($this->specs as $key => $spec) {
                    if (self::upstreams($spec) !== []) {
                        continue;
                    }
                    foreach ($this->batchDeps as $batchId => $condition) {
                        $conn->table($prefix.'job_batch_dependencies')->insert([
                            'job_id' => $ids[$key],
                            'depends_on_batch_id' => $batchId,
                            'edge_condition' => $condition,
                        ]);
                    }
                }
            }

            return $batch;
        });

        // Ergonomic fast path, NOT load-bearing: an upstream that was already
        // (or just went) terminal-not-succeeded dooms the fresh dependents now
        // instead of on the reaper's next stranded sweep — which remains the
        // correctness guarantee for any race this read loses.
        if ($this->batchDeps !== []) {
            $coordinator = $this->coordinator ?? app(BatchCoordinator::class);
            foreach (array_keys($this->batchDeps) as $upstreamId) {
                $upstream = Batch::find($upstreamId);
                if ($upstream !== null) {
                    $coordinator->cancelBatchDependents($upstream);
                }
            }
        }

        return $batch;
    }

    /**
     * Every upstream key of a member, whatever the edge condition — the edge
     * condition decides WHEN a dependency is satisfied, never whether it exists.
     *
     * @param  array{dependsOn:string[], dependsOnCompletion:string[], ...}  $spec
     * @return string[]
     */
    private static function upstreams(array $spec): array
    {
        return [...$spec['dependsOn'], ...$spec['dependsOnCompletion']];
    }

    /**
     * Kahn's algorithm: a DAG must have a topological order; a cycle would hang.
     * Both edge kinds count — an on_completion cycle deadlocks exactly as an
     * on_success one does.
     */
    private function assertAcyclic(): void
    {
        $indegree = [];
        $edges = [];
        foreach ($this->specs as $key => $spec) {
            $indegree[$key] ??= 0;
            foreach (self::upstreams($spec) as $dep) {
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
