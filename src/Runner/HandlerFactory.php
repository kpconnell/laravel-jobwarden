<?php

declare(strict_types=1);

namespace JobWarden\Runner;

use JobWarden\Contracts\JobWardenJob;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves a job handler from `jobs.job_class`, binding the job's stored params
 * (the JSON column) to constructor parameters BY NAME — the container fills every
 * unbound service dependency as before. The wire format stays plain JSON: params
 * remain inspectable in the dashboard, dispatchable over the HTTP API, and
 * deterministic across retries on other hosts.
 *
 * The rule handler authors code against (docs/JOB-AUTHORING.md): class-typed
 * constructor parameters are services; data parameters are scalar / array /
 * backed-enum / date-time and must appear in the params JSON (or declare a
 * default). Exactly two coercions bridge JSON to richer types:
 *
 *   backed enums   "full"                   → ImportMode::Full
 *   date-times     "2026-07-01T09:00:00Z"   → CarbonImmutable / DateTime…
 *
 * Eloquent models are deliberately NOT hydrated: pass the key in params and
 * fetch in handle(), where the missing-row policy (fail / park / skip) belongs
 * and where a retry sees current DB state by construction.
 *
 * Data-shaped types are also GUARDED when no params key matches. Without the
 * guard the container would "succeed" silently in exactly the wrong way — an
 * unbound `Carbon $asOf` resolves to NOW, an unbound model to an EMPTY instance.
 * Missing data must fail loud, not run wrong.
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
     * Named-parameter overrides for the container: params matched by exact
     * constructor-parameter name (coerced where the declared type calls for it);
     * everything unmatched is left for DI. Extra params keys are simply not
     * overrides — they stay reachable through JobContext::$params.
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
            $name = $parameter->getName();

            if (array_key_exists($name, $params)) {
                $overrides[$name] = $this->coerce($parameter, $params[$name], $jobClass);

                continue;
            }

            if (! $this->isDataParameter($parameter)) {
                continue; // service (or defaulted primitive): the container's job
            }

            // An optional data param falls back to ITS OWN declared default.
            // Left to the container, `?CarbonImmutable $asOf = null` would
            // resolve to now() and a defaulted model to an empty instance —
            // the default only kicks in when resolution FAILS, and here it
            // wouldn't.
            if ($parameter->isDefaultValueAvailable()) {
                $overrides[$name] = $parameter->getDefaultValue();

                continue;
            }

            $type = $parameter->getType();
            \assert($type instanceof \ReflectionNamedType);
            throw new \RuntimeException(
                "[{$jobClass}] constructor parameter \${$name} ({$type->getName()}) is data, not a service, "
                .'and the job has no matching params key. Add the key at dispatch or declare a default — '
                .'refusing container fallback, which would silently construct a wrong value '
                ."(an empty model, a date-time of 'now')."
            );
        }

        return $overrides;
    }

    /**
     * Data-shaped declared types: values with identity or an instant, which the
     * container can nonetheless "construct" out of thin air. These must come
     * from params (or a declared default), never from DI.
     */
    private function isDataParameter(\ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();
        if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return false;
        }

        $class = $type->getName();

        return is_a($class, Model::class, true)
            || is_a($class, \DateTimeInterface::class, true)
            || is_subclass_of($class, \BackedEnum::class);
    }

    /**
     * Bridge a JSON value to the parameter's declared type. Only backed enums
     * and date-times are coerced; scalars/arrays pass verbatim (the container
     * invokes constructors from weak-mode code, so `"42"` still binds to `int`).
     * Any other class type passes verbatim too — a mismatch is a TypeError,
     * which fails the attempt loud with the throw site recorded.
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

        if (is_a($class, Model::class, true)) {
            throw new \RuntimeException(
                "[{$jobClass}] constructor parameter \${$name} is an Eloquent model — model hydration is not "
                ."supported. Put the key in params (e.g. '{$name}Id') and fetch the model in handle(), "
                .'which owns the missing-row policy and sees current DB state on a retry.'
            );
        }

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
