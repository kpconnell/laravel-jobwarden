<?php

declare(strict_types=1);

namespace JobWarden\Dispatch;

use JobWarden\JobWarden;
use JobWarden\Models\Job;
use JobWarden\Runner\HandlerFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Fluent dispatch builder behind the Dispatchable trait. Collects options;
 * dispatch(...$args) then maps the args onto the job's (data-only) constructor,
 * down-converts them to the params-JSON wire format, and creates the row via
 * JobWarden::dispatch() — synchronously, inside its transaction, returning the
 * Job. There is deliberately no __destruct() commit: a dropped builder
 * dispatches nothing, and nothing ever commits at GC time.
 *
 * Before the row is created, the params are round-tripped through
 * HandlerFactory — the exact binding the worker will perform — so a dispatch
 * that would fail to bind on the other side fails HERE, at the dispatch site.
 * The instance that round-trip yields supplies idempotent(): the class
 * declaration is the single source of truth for the §3.4 recovery guard.
 */
final class PendingJob
{
    /** @var array<string,mixed> */
    private array $options = [];

    /** @param class-string $jobClass */
    public function __construct(private readonly string $jobClass)
    {
    }

    public function inLane(string $lane): self
    {
        $this->options['lane'] = $lane;

        return $this;
    }

    /** Delay eligibility: seconds from now (measured on the DB clock) or an absolute instant. */
    public function delay(int|\DateTimeInterface $delay): self
    {
        if ($delay instanceof \DateTimeInterface) {
            $this->options['available_at'] = $delay;
        } else {
            $this->options['delay'] = $delay;
        }

        return $this;
    }

    public function availableAt(\DateTimeInterface|string $at): self
    {
        $this->options['available_at'] = $at;

        return $this;
    }

    public function priority(int $priority): self
    {
        $this->options['priority'] = $priority;

        return $this;
    }

    public function maxAttempts(int $attempts): self
    {
        $this->options['max_attempts'] = $attempts;

        return $this;
    }

    public function maxRuntime(int $seconds): self
    {
        $this->options['max_runtime_sec'] = $seconds;

        return $this;
    }

    public function named(string $name): self
    {
        $this->options['name'] = $name;

        return $this;
    }

    public function idempotencyKey(string $key): self
    {
        $this->options['idempotency_key'] = $key;

        return $this;
    }

    /** @param array<int|string,mixed> $tags */
    public function tags(array $tags): self
    {
        $this->options['tags'] = $tags;

        return $this;
    }

    public function backoff(string $strategy): self
    {
        $this->options['backoff_strategy'] = $strategy;

        return $this;
    }

    public function createdBy(string $actor): self
    {
        $this->options['created_by'] = $actor;

        return $this;
    }

    /**
     * Terminal: map args → params, validate the round-trip, create the row.
     * Args are exactly what `new JobClass(...)` would accept — positional in
     * constructor order, named, or both.
     */
    public function dispatch(mixed ...$args): Job
    {
        $params = $this->toParams($args);

        // Construct the handler exactly as the worker will — HandlerFactory
        // verifies the JobWardenJob contract and binds every parameter — and
        // let the class declaration stamp the idempotency guard on the row.
        $handler = app(HandlerFactory::class)->make($this->jobClass, $params);
        $this->options['idempotent'] = $handler->idempotent();

        return app(JobWarden::class)->dispatch($this->jobClass, $params, $this->options);
    }

    /**
     * Map dispatch args onto constructor parameter names and down-convert each
     * value to the params-JSON wire format. Unknown named args and overflow
     * positional args are refused — this is the human-typed surface, where a
     * typo must fail at the dispatch site (raw JobWarden::dispatch()/API params
     * stay permissive for ride-along metadata).
     *
     * @param  array<int|string,mixed>  $args
     * @return array<string,mixed>
     */
    private function toParams(array $args): array
    {
        $constructor = (new \ReflectionClass($this->jobClass))->getConstructor();
        $names = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $constructor?->getParameters() ?? [],
        );

        $params = [];
        $positional = 0;
        foreach ($args as $key => $value) {
            if (is_int($key)) {
                if ($positional >= count($names)) {
                    throw new \InvalidArgumentException(
                        "[{$this->jobClass}] too many positional dispatch args — the constructor takes "
                        .count($names).'.'
                    );
                }
                $name = $names[$positional++];
            } else {
                $name = $key;
                if (! in_array($name, $names, true)) {
                    throw new \InvalidArgumentException(
                        "[{$this->jobClass}] unknown dispatch arg '{$name}' — args bind to constructor "
                        .'parameters by exact name'
                        .($names === [] ? ' (this job declares none).' : ': '.implode(', ', $names).'.')
                    );
                }
                if (array_key_exists($name, $params)) {
                    throw new \InvalidArgumentException(
                        "[{$this->jobClass}] dispatch arg '{$name}' passed both positionally and by name."
                    );
                }
            }

            $params[$name] = $this->toWire($name, $value);
        }

        return $params;
    }

    /**
     * The reverse of HandlerFactory's coercions: rich values down-convert to
     * plain JSON so the stored params stay inspectable, API-dispatchable, and
     * deterministic on retry. Models and arbitrary objects are refused — at
     * the dispatch site, which beats failing in the child.
     */
    private function toWire(string $name, mixed $value): mixed
    {
        if ($value instanceof Model) {
            throw new \InvalidArgumentException(
                "[{$this->jobClass}] dispatch arg '{$name}' is an Eloquent model — params are plain JSON. "
                ."Pass the key (e.g. '{$name}Id') and fetch the model in handle(), which owns the "
                .'missing-row policy and sees current DB state on a retry.'
            );
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM); // explicit offset: an unambiguous instant
        }

        if (is_object($value)) {
            throw new \InvalidArgumentException(
                "[{$this->jobClass}] dispatch arg '{$name}' (".$value::class.') does not fit the params '
                .'JSON — pass scalars, arrays, backed enums, or date-times.'
            );
        }

        return $value;
    }
}
