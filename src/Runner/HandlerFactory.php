<?php

declare(strict_types=1);

namespace JobWarden\Runner;

use JobWarden\Contracts\JobWardenJob;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves a job handler from `jobs.job_class`, binding the job's stored params
 * (the JSON column) to constructor parameters BY NAME. The wire format stays
 * plain JSON: params remain inspectable in the dashboard, dispatchable over the
 * HTTP API, and deterministic across retries on other hosts.
 *
 * The rule handler authors code against (docs/JOB-AUTHORING.md): constructors
 * are DATA-ONLY. Every parameter is scalar / array / backed-enum / date-time —
 * a literal mirror of the params JSON — and must appear in the params (or
 * declare a default). Services are received in handle(), which ChildRunner
 * invokes through the container (method injection); a class-typed constructor
 * parameter that isn't an enum or date-time is refused before construction.
 * Exactly two coercions bridge JSON to richer types:
 *
 *   backed enums   "full"                   → ImportMode::Full
 *   date-times     "2026-07-01T09:00:00Z"   → CarbonImmutable / DateTime…
 *
 * Eloquent models are deliberately NOT hydrated: pass the key in params and
 * fetch in handle(), where the missing-row policy (fail / park / skip) belongs
 * and where a retry sees current DB state by construction.
 *
 * Nothing is ever left for the container to invent. Before the data-only rule,
 * an unbound data-shaped parameter had to be guarded case by case — the
 * container would "succeed" silently in exactly the wrong way (an unbound
 * `Carbon $asOf` resolves to NOW, an unbound model to an EMPTY instance). Now
 * every parameter is bound, defaulted, or refused loudly.
 */
final class HandlerFactory
{
    /**
     * @param  array<string,mixed>  $params
     */
    public function make(string $jobClass, array $params): JobWardenJob
    {
        // Verify the contract BEFORE construction (string check: autoloads, never
        // instantiates) — a wrong class must not get to run constructor side effects.
        if (! is_subclass_of($jobClass, JobWardenJob::class)) {
            throw new \RuntimeException("{$jobClass} must implement ".JobWardenJob::class);
        }

        return app()->make($jobClass, $this->overrides($jobClass, $params));
    }

    /**
     * One override per constructor parameter: the matching params key (coerced
     * where the declared type calls for it) or the parameter's own declared
     * default. Params keys that match no parameter are ignored by binding
     * (schedule/API metadata may ride along); a parameter with neither a key
     * nor a default is refused loudly.
     *
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function overrides(string $jobClass, array $params): array
    {
        $constructor = (new \ReflectionClass($jobClass))->getConstructor();
        if ($constructor === null) {
            return [];
        }

        $overrides = [];
        foreach ($constructor->getParameters() as $parameter) {
            $this->assertDataParameter($parameter, $jobClass);
            $name = $parameter->getName();

            if (array_key_exists($name, $params)) {
                $overrides[$name] = $this->coerce($parameter, $params[$name], $jobClass);

                continue;
            }

            // Set the declared default EXPLICITLY rather than leaving it to the
            // container — a default only kicks in there when resolution FAILS,
            // and for `?CarbonImmutable $asOf = null` it wouldn't fail: the
            // container would construct a date-time of "now".
            if ($parameter->isDefaultValueAvailable()) {
                $overrides[$name] = $parameter->getDefaultValue();

                continue;
            }

            if ($parameter->isVariadic()) {
                continue;
            }

            $type = $parameter->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : (string) $type;
            throw new \RuntimeException(
                "[{$jobClass}] constructor parameter \${$name} ({$typeName}) has no matching params key "
                .'and no default. Add the key at dispatch or declare a default.'
            );
        }

        return $overrides;
    }

    /**
     * Constructors are data-only. Builtins, backed enums and date-times are the
     * job's payload; a model is refused with the pass-the-id rule; any other
     * class/interface type is a service in the wrong place — it belongs in
     * handle(), where the container injects it per-run.
     */
    private function assertDataParameter(\ReflectionParameter $parameter, string $jobClass): void
    {
        $type = $parameter->getType();
        if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return; // untyped / builtin / union: data, bound or defaulted verbatim
        }

        $class = $type->getName();
        $name = $parameter->getName();

        if (is_a($class, Model::class, true)) {
            throw new \RuntimeException(
                "[{$jobClass}] constructor parameter \${$name} is an Eloquent model — model hydration is not "
                ."supported. Put the key in params (e.g. '{$name}Id') and fetch the model in handle(), "
                .'which owns the missing-row policy and sees current DB state on a retry.'
            );
        }

        if (is_subclass_of($class, \BackedEnum::class) || is_a($class, \DateTimeInterface::class, true)) {
            return;
        }

        throw new \RuntimeException(
            "[{$jobClass}] constructor parameter \${$name} ({$class}) is a service — constructors are "
            .'data-only, a mirror of the params JSON (scalar / array / backed-enum / date-time). '
            .'Receive services in handle() instead; it is invoked through the container.'
        );
    }

    /**
     * Bridge a JSON value to the parameter's declared type. Only backed enums
     * and date-times are coerced; scalars/arrays pass verbatim (the container
     * invokes constructors from weak-mode code, so `"42"` still binds to `int`)
     * — a mismatch is a TypeError, which fails the attempt loud with the throw
     * site recorded. assertDataParameter() has already refused any other class
     * type before a value reaches here.
     */
    private function coerce(\ReflectionParameter $parameter, mixed $value, string $jobClass): mixed
    {
        $type = $parameter->getType();
        if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return $value;
        }

        if ($value === null && $type->allowsNull()) {
            return null;
        }

        $class = $type->getName();
        $name = $parameter->getName();

        if (is_subclass_of($class, \BackedEnum::class)) {
            return $this->toEnum($class, $value, $name, $jobClass);
        }

        if (is_a($class, \DateTimeInterface::class, true)) {
            return $this->toDate($class, $value, $name, $jobClass);
        }

        return $value;
    }

    /**
     * @param  class-string<\BackedEnum>  $enum
     */
    private function toEnum(string $enum, mixed $value, string $name, string $jobClass): \BackedEnum
    {
        $backing = (string) (new \ReflectionEnum($enum))->getBackingType();

        // HTTP-sourced params arrive loosely typed; normalize toward the backing
        // type so "3" binds an int-backed case instead of TypeErroring.
        if ($backing === 'int' && is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1) {
            $value = (int) $value;
        }
        if ($backing === 'string' && (is_int($value) || is_float($value))) {
            $value = (string) $value;
        }

        $case = ($backing === 'int' && is_int($value)) || ($backing === 'string' && is_string($value))
            ? $enum::tryFrom($value)
            : null;

        return $case ?? throw new \RuntimeException(
            "[{$jobClass}] param '{$name}': ".json_encode($value)." is not a valid {$enum}; expected one of ["
            .implode(', ', array_map(static fn (\BackedEnum $c) => (string) $c->value, $enum::cases())).']'
        );
    }

    /**
     * @param  class-string  $class  the declared date type (class or interface)
     */
    private function toDate(string $class, mixed $value, string $name, string $jobClass): \DateTimeInterface
    {
        if (! is_string($value) || trim($value) === '') {
            throw new \RuntimeException(
                "[{$jobClass}] param '{$name}': expected an ISO-8601 date-time string for {$class}, got "
                .json_encode($value).'.'
            );
        }

        // Interface-typed params (DateTimeInterface, CarbonInterface) get the
        // immutable default; concrete classes are constructed as declared.
        $concrete = (new \ReflectionClass($class))->isInstantiable() ? $class : CarbonImmutable::class;

        try {
            $date = new $concrete($value);
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "[{$jobClass}] param '{$name}': cannot parse ".json_encode($value)." as {$class} — pass an "
                .'ISO-8601 string, with an explicit offset (e.g. "2026-07-01T09:00:00Z") so the instant is '
                .'unambiguous.',
                previous: $e,
            );
        }

        \assert($date instanceof \DateTimeInterface);

        return $date;
    }
}
