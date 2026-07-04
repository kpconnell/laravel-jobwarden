<?php

declare(strict_types=1);

namespace JobWarden\Tests\Dispatch;

use JobWarden\Contracts\JobWardenJob;
use JobWarden\Dispatch\Dispatchable;
use JobWarden\Models\Job;
use JobWarden\Models\Worker;
use JobWarden\Runner\JobContext;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Carbon\CarbonImmutable;

/**
 * Horizon-style dispatch: JobClass::dispatch(args...) maps positional/named
 * args onto the (data-only) constructor, down-converts rich values to the
 * params JSON, stamps idempotency from the class declaration, and the
 * JobClass::option()->…->dispatch() builder mirrors the options array. The
 * round-trip through HandlerFactory makes a bad dispatch fail at the dispatch
 * site — before any row exists.
 */
final class DispatchableTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
    }

    // -- argument mapping ----------------------------------------------------

    public function test_positional_args_map_in_constructor_order(): void
    {
        $job = TraitedJob::dispatch('store-1', DispatchMode::Slow);

        $this->assertInstanceOf(Job::class, $job);
        $this->assertSame(TraitedJob::class, $job->job_class);
        $this->assertSame(['store' => 'store-1', 'mode' => 'slow'], $job->params);
        $this->assertSame(JobState::Queued, $job->state);
        $this->assertSame('default', $job->lane);
    }

    public function test_named_args_map_by_exact_name_and_dates_serialize_with_offset(): void
    {
        $job = TraitedJob::dispatch(store: 'store-2', asOf: new CarbonImmutable('2026-07-01T09:00:00+02:00'));

        $this->assertSame('store-2', $job->params['store']);
        $this->assertSame('2026-07-01T09:00:00+02:00', $job->params['asOf'], 'ISO-8601 with explicit offset');
        $this->assertArrayNotHasKey('mode', $job->params, 'defaulted params are not stored');
    }

    public function test_positional_and_named_args_mix(): void
    {
        $job = TraitedJob::dispatch('store-3', limit: 5);

        $this->assertSame(['store' => 'store-3', 'limit' => 5], $job->params);
    }

    public function test_unknown_named_arg_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("unknown dispatch arg 'stroe'");

        TraitedJob::dispatch(stroe: 'typo');
    }

    public function test_too_many_positional_args_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('too many positional dispatch args');

        NoCtorJob::dispatch('unexpected');
    }

    public function test_same_arg_positionally_and_by_name_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'store' passed both positionally and by name");

        TraitedJob::dispatch('store-4', store: 'store-4-again');
    }

    public function test_missing_required_arg_fails_at_the_dispatch_site_with_no_row(): void
    {
        // The params round-trip through HandlerFactory BEFORE the row is
        // created — the exact binding the worker would perform.
        try {
            TraitedJob::dispatch(limit: 3); // no 'store'
            $this->fail('expected the dispatch to be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('has no matching params key', $e->getMessage());
        }

        $this->assertSame(0, Job::query()->count(), 'a refused dispatch must not create a row');
    }

    // -- wire-format down-conversion ------------------------------------------

    public function test_enum_instances_store_their_backing_value(): void
    {
        $job = TraitedJob::dispatch(store: 's', mode: DispatchMode::Fast);

        $this->assertSame('fast', $job->params['mode']);
    }

    public function test_model_args_are_refused_with_the_pass_the_id_rule(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is an Eloquent model');

        TraitedJob::dispatch(store: new Worker);
    }

    public function test_arbitrary_objects_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not fit the params JSON');

        TraitedJob::dispatch(store: new \stdClass);
    }

    // -- idempotency single-sourcing -------------------------------------------

    public function test_idempotency_is_stamped_from_the_class_declaration(): void
    {
        $this->assertTrue((bool) TraitedJob::dispatch('s')->idempotent);
        $this->assertFalse((bool) SingleShotJob::dispatch('ref-1')->idempotent);
    }

    // -- the options builder ----------------------------------------------------

    public function test_builder_options_land_on_the_row(): void
    {
        $job = TraitedJob::inLane('reports')
            ->priority(7)
            ->maxAttempts(4)
            ->maxRuntime(120)
            ->named('nightly-import')
            ->idempotencyKey('key-1')
            ->tags(['team' => 'reports', 'cadence' => 'nightly'])
            ->backoff('fixed')
            ->createdBy('tester')
            ->dispatch('store-9');

        $this->assertSame('reports', $job->lane);
        $this->assertSame(7, (int) $job->priority);
        $this->assertSame(4, (int) $job->max_attempts);
        $this->assertSame(120, (int) $job->max_runtime_sec);
        $this->assertSame('nightly-import', $job->name);
        $this->assertSame('key-1', $job->idempotency_key);
        $this->assertSame(['cadence' => 'nightly', 'team' => 'reports'], $job->tags->sortBy('name')->pluck('value', 'name')->all());
        $this->assertSame('fixed', $job->backoff_strategy);
        $this->assertSame('tester', $job->created_by);
    }

    public function test_delay_in_seconds_gates_the_job_as_pending(): void
    {
        // Two days: far beyond any app↔DB timezone skew a cast could introduce.
        $job = TraitedJob::delay(2 * 86400)->dispatch('s');

        $this->assertSame(JobState::Pending, $job->state);
        $this->assertNull($job->queued_at, 'a gated job is not queued yet');
        $this->assertNotNull($job->available_at);
    }

    public function test_a_past_available_at_dispatches_immediately(): void
    {
        $job = TraitedJob::availableAt('2020-01-01T00:00:00Z')->dispatch('s');

        $this->assertSame(JobState::Queued, $job->state);
        $this->assertNotNull($job->queued_at);
    }

    public function test_a_job_without_a_constructor_dispatches_bare(): void
    {
        $job = NoCtorJob::dispatch();

        $this->assertSame(JobState::Queued, $job->state);
        $this->assertSame([], (array) ($job->params ?? []));
    }
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

enum DispatchMode: string
{
    case Fast = 'fast';
    case Slow = 'slow';
}

final class TraitedJob implements JobWardenJob
{
    use Dispatchable;

    public function __construct(
        public readonly string $store,
        public readonly ?DispatchMode $mode = null,
        public readonly ?CarbonImmutable $asOf = null,
        public readonly int $limit = 10,
    ) {
    }

    public function handle(JobContext $context): void
    {
    }

    public function idempotent(): bool
    {
        return true;
    }
}

final class SingleShotJob implements JobWardenJob
{
    use Dispatchable;

    public function __construct(public readonly string $ref)
    {
    }

    public function handle(JobContext $context): void
    {
    }

    public function idempotent(): bool
    {
        return false;
    }
}

final class NoCtorJob implements JobWardenJob
{
    use Dispatchable;

    public function handle(JobContext $context): void
    {
    }

    public function idempotent(): bool
    {
        return true;
    }
}
