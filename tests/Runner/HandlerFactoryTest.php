<?php

declare(strict_types=1);

namespace JobWarden\Tests\Runner;

use JobWarden\Contracts\JobWardenJob;
use JobWarden\Models\Worker;
use JobWarden\Runner\HandlerFactory;
use JobWarden\Runner\JobContext;
use JobWarden\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;

/**
 * Constructor param binding: params (JSON) matched to constructor parameters by
 * exact name, enums/date-times coerced. Constructors are DATA-ONLY — a
 * service-typed parameter is refused (services are method-injected into
 * handle()), and the silent-wrong container fallbacks (empty Model, Carbon
 * "now") are loud failures.
 */
final class HandlerFactoryTest extends TestCase
{
    private function factory(): HandlerFactory
    {
        return new HandlerFactory;
    }

    public function test_binds_scalars_and_arrays_by_name(): void
    {
        $job = $this->factory()->make(ScalarParamsJob::class, [
            'name' => 'catalog',
            'count' => 3,
            'tags' => ['a', 'b'],
            'unrelated' => 'ignored by binding (schedule/API metadata may ride along)',
        ]);

        \assert($job instanceof ScalarParamsJob);
        $this->assertSame('catalog', $job->name);
        $this->assertSame(3, $job->count);
        $this->assertSame(['a', 'b'], $job->tags);
    }

    public function test_service_typed_constructor_param_is_refused(): void
    {
        // Constructors are data-only: services belong in handle(), where the
        // container method-injects them per-run.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is a service');

        $this->factory()->make(ServiceParamJob::class, ['name' => 'n']);
    }

    public function test_numeric_string_binds_to_int_param_via_weak_coercion(): void
    {
        // HTTP-sourced params arrive as strings; the container invokes the
        // constructor from weak-mode code, so "42" → int 42. Locked here as
        // documented behavior.
        $job = $this->factory()->make(ScalarParamsJob::class, ['name' => 'n', 'count' => '42']);

        \assert($job instanceof ScalarParamsJob);
        $this->assertSame(42, $job->count);
    }

    public function test_optional_scalar_falls_back_to_its_declared_default(): void
    {
        $job = $this->factory()->make(ScalarParamsJob::class, ['name' => 'n', 'count' => 1]);

        \assert($job instanceof ScalarParamsJob);
        $this->assertSame([], $job->tags);
    }

    public function test_missing_required_scalar_fails_loud(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has no matching params key');

        $this->factory()->make(ScalarParamsJob::class, ['count' => 1]); // no 'name'
    }

    public function test_string_backed_enum_is_coerced_from_its_value(): void
    {
        $job = $this->factory()->make(EnumParamsJob::class, ['mode' => 'on', 'level' => 3]);

        \assert($job instanceof EnumParamsJob);
        $this->assertSame(TestMode::On, $job->mode);
        $this->assertSame(TestLevel::High, $job->level);
    }

    public function test_int_backed_enum_accepts_a_numeric_string(): void
    {
        $job = $this->factory()->make(EnumParamsJob::class, ['mode' => 'off', 'level' => '1']);

        \assert($job instanceof EnumParamsJob);
        $this->assertSame(TestLevel::Low, $job->level);
    }

    public function test_invalid_enum_value_lists_the_valid_cases(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expected one of [on, off]');

        $this->factory()->make(EnumParamsJob::class, ['mode' => 'sideways', 'level' => 1]);
    }

    public function test_explicit_null_passes_into_a_nullable_param(): void
    {
        $job = $this->factory()->make(OptionalDataJob::class, ['asOf' => null, 'mode' => null]);

        \assert($job instanceof OptionalDataJob);
        $this->assertNull($job->asOf);
        $this->assertNull($job->mode);
    }

    public function test_date_params_are_parsed_per_their_declared_type(): void
    {
        $job = $this->factory()->make(DateParamsJob::class, [
            'asOf' => '2026-07-01T09:30:00+02:00',
            'plain' => '2026-07-01T00:00:00Z',
            'anyDate' => '2026-01-15T12:00:00Z',
        ]);

        \assert($job instanceof DateParamsJob);
        $this->assertInstanceOf(CarbonImmutable::class, $job->asOf);
        $this->assertSame('2026-07-01T09:30:00+02:00', $job->asOf->toIso8601String(), 'offset preserved');
        $this->assertInstanceOf(\DateTimeImmutable::class, $job->plain);
        // Interface-typed → the immutable default.
        $this->assertInstanceOf(CarbonImmutable::class, $job->anyDate);
    }

    public function test_unparseable_date_fails_with_the_param_name(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("param 'asOf'");

        $this->factory()->make(DateParamsJob::class, [
            'asOf' => 'not a date',
            'plain' => '2026-07-01T00:00:00Z',
            'anyDate' => '2026-01-15T12:00:00Z',
        ]);
    }

    public function test_non_string_date_value_fails_loud(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expected an ISO-8601 date-time string');

        $this->factory()->make(DateParamsJob::class, [
            'asOf' => 1751356800, // ambiguous (seconds? ms?) — deliberately refused
            'plain' => '2026-07-01T00:00:00Z',
            'anyDate' => '2026-01-15T12:00:00Z',
        ]);
    }

    public function test_unbound_optional_date_gets_its_default_not_now(): void
    {
        // THE footgun: without the guard, the container would resolve an unbound
        // CarbonImmutable to now() — the default value would never be reached.
        $job = $this->factory()->make(OptionalDataJob::class, []);

        \assert($job instanceof OptionalDataJob);
        $this->assertNull($job->asOf);
        $this->assertNull($job->mode);
    }

    public function test_unbound_required_date_is_refused_not_resolved_to_now(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has no matching params key');

        $this->factory()->make(DateParamsJob::class, [
            'plain' => '2026-07-01T00:00:00Z',
            'anyDate' => '2026-01-15T12:00:00Z',
        ]);
    }

    public function test_model_typed_param_is_refused_even_with_a_matching_key(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('model hydration is not supported');

        $this->factory()->make(ModelParamJob::class, ['worker' => 'w-1']);
    }

    public function test_unbound_model_param_is_refused_not_resolved_to_an_empty_instance(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('model hydration is not supported');

        $this->factory()->make(ModelParamJob::class, []);
    }

    public function test_non_jobwarden_class_is_rejected_before_construction(): void
    {
        NotAJob::$constructed = false;

        try {
            $this->factory()->make(NotAJob::class, []);
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('must implement', $e->getMessage());
        }

        $this->assertFalse(NotAJob::$constructed, 'constructor side effects must not run for a rejected class');
    }

    public function test_handler_without_a_constructor_resolves(): void
    {
        $this->assertInstanceOf(BareJob::class, $this->factory()->make(BareJob::class, ['ignored' => 1]));
    }
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

enum TestMode: string
{
    case On = 'on';
    case Off = 'off';
}

enum TestLevel: int
{
    case Low = 1;
    case High = 3;
}

abstract class FixtureJob implements JobWardenJob
{
    public function handle(JobContext $context): void
    {
    }

    public function idempotent(): bool
    {
        return true;
    }
}

final class ScalarParamsJob extends FixtureJob
{
    public function __construct(
        public readonly string $name,
        public readonly int $count,
        public readonly array $tags = [],
    ) {
    }
}

final class ServiceParamJob extends FixtureJob
{
    public function __construct(
        public readonly Repository $config,
        public readonly string $name,
    ) {
    }
}

final class EnumParamsJob extends FixtureJob
{
    public function __construct(
        public readonly TestMode $mode,
        public readonly TestLevel $level,
    ) {
    }
}

final class DateParamsJob extends FixtureJob
{
    public function __construct(
        public readonly CarbonImmutable $asOf,
        public readonly \DateTimeImmutable $plain,
        public readonly \DateTimeInterface $anyDate,
    ) {
    }
}

final class OptionalDataJob extends FixtureJob
{
    public function __construct(
        public readonly ?CarbonImmutable $asOf = null,
        public readonly ?TestMode $mode = null,
    ) {
    }
}

final class ModelParamJob extends FixtureJob
{
    public function __construct(public readonly Worker $worker)
    {
    }
}

final class BareJob extends FixtureJob
{
}

final class NotAJob
{
    public static bool $constructed = false;

    public function __construct()
    {
        self::$constructed = true;
    }
}
