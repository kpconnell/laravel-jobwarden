# Job Authoring Guide

How to write a JobWarden job handler: the contract, constructor param binding,
what types params can be, and the runtime facilities (`JobContext`, logging,
artifacts, graceful stop) available inside a run.

The one design fact everything below follows from: **a job's params are a plain
JSON column** (`jobs.params`), not a serialized object graph. That is what makes
params inspectable in the dashboard, dispatchable over the HTTP API, editable by
an operator before a re-dispatch, and deterministic when a retry lands on
another host. Handlers are rehydrated *from data*, never unserialized.

## The contract

```php
use JobWarden\Contracts\JobWardenJob;
use JobWarden\Runner\JobContext;

final class ImportCatalog implements JobWardenJob
{
    public function handle(JobContext $context): void
    {
        // returning normally = success · throwing = failure
    }

    public function idempotent(): bool
    {
        return true; // safe to re-run a lost/failed attempt
    }
}
```

Every job runs in a dedicated child process with fencing-token verification,
`/proc` liveness stamps, and reaper recovery — none of which the handler has to
think about.

## Constructor param binding

The handler is resolved from the container in the child process, and the job's
stored params are **matched to constructor parameters by exact name**. Anything
not matched by a params key is resolved by the container as a service:

```php
final class ImportCatalog implements JobWardenJob
{
    public function __construct(
        private readonly CatalogClient $client,          // service: container DI
        private readonly string $storeId,                // data: required param
        private readonly ImportMode $mode,               // data: backed enum, from "full"
        private readonly int $limit = 100,               // data: optional param
        private readonly ?CarbonImmutable $asOf = null,  // data: optional date-time
    ) {
    }
    // ...
}
```

```php
app(JobWarden::class)->dispatch(ImportCatalog::class, [
    'storeId' => 'store-42',
    'mode'    => 'full',
    'asOf'    => '2026-07-01T09:00:00Z',
], ['idempotent' => true, 'max_attempts' => 3]);
```

The rule, in one sentence: **class-typed constructor parameters are services;
data parameters are scalar / array / backed-enum / date-time, and must appear in
the params JSON (or declare a default).**

Notes:

- **Matching is by exact PHP parameter name.** `store_id` will never bind to
  `$storeId`. Use the parameter name as the params key.
- **Params keys that match no constructor parameter are fine** — they simply
  aren't bound, and remain available as `$context->params['key']`. The full
  params array always reaches `JobContext`, so context-style handlers keep
  working unchanged; binding is opt-in per parameter.
- **Scalars coerce weakly.** HTTP-sourced params arrive loosely typed; `"42"`
  binds to an `int` parameter. `"banana"` into `int` is a TypeError → the
  attempt fails loud.

### Supported param types

| Declared type | Params JSON | Behavior |
| --- | --- | --- |
| `string` / `int` / `float` / `bool` | scalar | bound verbatim (weak coercion) |
| `array` | array / object | bound verbatim |
| backed enum (`ImportMode`) | its backing value (`"full"`, `3`) | coerced via `::from()`; an invalid value fails listing the valid cases |
| `CarbonImmutable`, `Carbon`, `DateTimeImmutable`, `DateTime` | ISO-8601 string | parsed as the declared class |
| `DateTimeInterface` / `CarbonInterface` | ISO-8601 string | parsed as `CarbonImmutable` |
| nullable / defaulted any-of-the-above | `null` or omitted | `null` passes through; an omitted key uses the declared default |

Date-time strings should carry an **explicit offset** (`2026-07-01T09:00:00Z`);
an offsetless string is parsed in the app's default timezone, which makes the
instant deployment-dependent.

### Deliberately unsupported: Eloquent models

A model-typed constructor parameter is **refused** — even with a matching params
key. Pass the key, fetch in `handle()`:

```php
public function __construct(private readonly string $storeId) {}

public function handle(JobContext $context): void
{
    $store = Store::findOrFail($this->storeId);
    // ...
}
```

This is the same behavior Laravel's `SerializesModels` gives you (it stores
class + id and refetches on wake) minus the magic — and here it is load-bearing:
a retry may run minutes later **on another host**, so the handler must decide
what a missing/changed row means (fail? park? skip?), and the dashboard's params
column stays meaningful (`{"storeId": "store-42"}`).

Rich param sets that want structure can hydrate a DTO explicitly at the top of
`handle()` from `$context->params`. Keep constructor binding flat.

### Failure modes are loud, by design

| Mistake | What happens |
| --- | --- |
| required data param missing from params | resolution fails; attempt fails with the message recorded on `job_attempts.error` / `jobs.last_error` |
| invalid enum value | fails, listing the valid cases |
| unparseable / non-string date value | fails, naming the param |
| unbound `Model` / date-time param | **refused** rather than resolved — the container would otherwise silently construct an *empty model* or a date of *"now"* |
| class doesn't implement `JobWardenJob` | rejected before the constructor runs |

The handler never starts in any of these cases, so a binding failure can never
half-execute a non-idempotent job. Whether the failure then retries or parks is
the normal recovery decision: `idempotent → retry within budget, else park`.

## JobContext

`handle(JobContext $context)` receives:

- `$context->jobId`, `$context->attemptId`, `$context->attemptNumber` — identity,
  useful for idempotency keys and logging.
- `$context->params` — the **full** params array, including keys already bound
  to the constructor.
- `$context->artifact(type, name, opts)` — attach a support-case artifact
  (request/response pair, file on a disk, dump) to this attempt; bundled by
  `jobwarden:logs --export`.
- `$context->result([...])` — store the job's completion payload (see Results).

## Results

A handler that produces a completion payload stores it with:

```php
public function handle(JobContext $context): void
{
    // ... do the work ...
    $context->result(['imported' => $count, 'report_artifact_id' => $artifactId]);
}
```

The payload lands in `jobs.result` (JSON) and is returned by `GET /jobs/{id}` —
the caller dispatches, holds the job id, polls for a terminal `state`, and reads
`result` when it's `succeeded`. Semantics:

- **Committed atomically with the succeeded transition.** A poller can never see
  `state = succeeded` without the result; a fenced-out child (reaped while
  finishing) never lands one. Nothing persists unless the run succeeds — the
  result is the success-mirror of `last_error`.
- **Last call wins.** Calling `result()` repeatedly just replaces the buffer.
- **Bounded.** Payloads over `jobwarden.results.max_bytes` (default 64 KB) — or
  payloads that aren't JSON-encodable — throw at the call site and fail the run
  loudly.

Generally speaking, **results are not freight**. The result is a completion
summary the poller can act on — counts, ids, statuses, pointers. If the job
produces actual output data (an export, a generated file, a big report), ship
it where bulk data belongs — an S3/filesystem-disk upload, recorded with
`$context->artifact(...)` — and put the location or artifact id in the result.
The size cap exists to enforce exactly this: the hot `jobs` table is not a
blob store.

For **progress** during a run, use logging (below), not `result()` — the result
is a completion payload, invisible until the job succeeds.

## Idempotency

`idempotent(): bool` declares whether a lost or failed run may be automatically
re-executed. The guard is strictly binary: **idempotent → retry** (a fresh
attempt, possibly on another host), **non-idempotent → park** for an operator —
there is no third category.

Note that the engine's recovery decision reads the **`jobs.idempotent` column**,
set at dispatch/schedule time (`['idempotent' => true]`); the interface method
declares the class's intent. Keep them consistent — declare it in the class,
assert it at dispatch.

## Logging

Every `Log::` facade call made during a run is captured into `job_logs`, scoped
to the attempt — viewable live in the dashboard and via `jobwarden:logs`. Write
progress markers; they are the timeline an operator sees next to the recorded
error when something parks.

## Long-running jobs and graceful stop

On deploy/scale-down the supervisor sends SIGTERM and escalates to SIGKILL after
the grace window. A handler that implements `JobWarden\Contracts\Terminable`
gets `onTerminate()` as a checkpoint window. Non-cooperative handlers are killed
and recorded `stopped`; set `max_runtime_sec` so a hung run is reaped rather
than trusted forever.

## Dispatching reference

```php
$jw = app(JobWarden::class);

// one-off
$jw->dispatch(ImportCatalog::class, ['storeId' => 'store-42'], ['idempotent' => true]);

// recurring / one-time schedule (params stored on the schedule row, copied to each run)
$jw->schedule('catalog-nightly', '0 2 * * *', ImportCatalog::class, ['storeId' => 'store-42'], ['idempotent' => true]);
$jw->scheduleOnce('backfill', now()->addHour(), ImportCatalog::class, ['storeId' => 'store-42']);

// batch member
$jw->batch('nightly-sync')
    ->add('import', ImportCatalog::class, ['storeId' => 'store-42'])
    ->dispatch();
```

```jsonc
// HTTP API — same key rule; job_class is validated against the contract
POST /jobwarden/api/jobs
{"job_class": "App\\Jobs\\ImportCatalog", "params": {"storeId": "store-42"}, "idempotent": true}
```
