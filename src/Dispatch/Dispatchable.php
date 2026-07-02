<?php

declare(strict_types=1);

namespace JobWarden\Dispatch;

use JobWarden\Models\Job;

/**
 * Opt-in Horizon-style dispatch sugar for JobWardenJob classes:
 *
 *     SendInvoice::dispatch(42, ImportMode::Full);
 *     SendInvoice::dispatch(invoiceId: 42, mode: ImportMode::Full);
 *     SendInvoice::inLane('reports')->delay(300)->dispatch(42, ImportMode::Full);
 *
 * Arguments — positional (constructor order), named, or both — become the
 * params JSON; rich values are down-converted to the wire format (backed enum
 * → its value, date-time → ISO-8601 with offset). The class's idempotent()
 * declaration stamps the row, so it is never repeated at the dispatch site.
 *
 * This honors the spec §0 non-goal — Laravel's dispatch()/Bus is NOT hijacked;
 * nothing global is intercepted. But the method name collides by design: a
 * class using this trait cannot also use Illuminate\Foundation\Bus\Dispatchable,
 * and Bus::fake()/Queue::fake() never see these dispatches — assert on the
 * jobs table instead.
 */
trait Dispatchable
{
    /** Create the job now (queued, or pending if delayed) and return the row. */
    public static function dispatch(mixed ...$args): Job
    {
        return (new PendingJob(static::class))->dispatch(...$args);
    }

    /**
     * Option methods (inLane, delay, priority, maxAttempts, …) start a builder:
     * MyJob::inLane('x')->delay(60)->dispatch(...). dispatch() is always the
     * terminal call — a builder that never reaches it dispatches nothing
     * (deliberately no __destruct() commit, unlike Laravel's PendingDispatch).
     *
     * @param  array<int,mixed>  $arguments
     */
    public static function __callStatic(string $method, array $arguments): PendingJob
    {
        return (new PendingJob(static::class))->{$method}(...$arguments);
    }
}
