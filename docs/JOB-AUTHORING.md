# Job Authoring Guide

How to write a JobWarden job handler: the contract, constructor param binding,
services in `handle()`, dispatching (including `JobClass::dispatch(...)`), and
the runtime facilities (`JobContext`, logging, artifacts, graceful stop)
available inside a run.

The one design fact everything below follows from: **a job's params are a plain
JSON column** (`jobs.params`), not a serialized object graph. That is what makes
params inspectable in the dashboard, dispatchable over the HTTP API, editable by
an operator before a re-dispatch, and deterministic when a retry lands on
another host. Handlers are rehydrated *from data*, never unserialized.

The authoring rule, in one sentence: **the constructor carries data, `handle()`
receives services.** The constructor is a literal mirror of the params JSON;
the container method-injects anything class-typed into `handle()` per-run.

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

## Constructor param binding: data only

The handler is resolved from the container in the child process, and the job's
stored params are **matched to constructor parameters by exact name**.
Constructors are **data-only**: every parameter is scalar / array / backed-enum
/ date-time, and must appear in the params JSON or declare a default. A
service-typed constructor parameter is refused loudly — services belong in
`handle()` (next section).

```php
final class ImportCatalog implements JobWardenJob
{
    public function __construct(
        private readonly string $storeId,                // required param
        private readonly ImportMode $mode,               // backed enum, from "full"
        private readonly int $limit = 100,               // optional param
        private readonly ?CarbonImmutable $asOf = null,  // optional date-time
    ) {
    }

    public function handle(JobContext $context, ?CatalogClient $client = null): void
    {
        // $client is container-injected per-run — see "Services in handle()"
    }
    // ...
}
```

```php
ImportCatalog::dispatch(storeId: 'store-42', mode: ImportMode::Full, asOf: now()->toImmutable());
// or, without the trait:
app(JobWarden::class)->dispatch(ImportCatalog::class, [
    'storeId' => 'store-42',
    'mode'    => 'full',
    'asOf'    => '2026-07-01T09:00:00Z',
], ['idempotent' => true, 'max_attempts' => 3]);
```

Notes:

- **Matching is by exact PHP parameter name.** `store_id` will never bind to
  `$storeId`. Use the parameter name as the params key.
- **Params keys that match no constructor parameter are ignored by binding** —
  schedule/API metadata can ride along in the JSON. (Trait dispatch is stricter:
  `JobClass::dispatch(...)` refuses an unknown arg, because there a stray key is
  a typo, not metadata.)
- **Scalars coerce weakly.** HTTP-sourced params arrive loosely typed; `"42"`
  binds to an `int` parameter. `"banana"` into `int` is a TypeError → the
  attempt fails loud.

## Services in handle()

`handle()` is invoked **through the container**, so class-typed parameters
beyond `JobContext` are method-injected per-run:

```php
public function handle(JobContext $context, ?Mailer $mailer = null, ?CatalogClient $client = null): void
{
    // $mailer / $client resolve from the container — never null in practice
}
```

Because the `JobWardenJob` interface pins `handle(JobContext $context)`, PHP
requires added parameters to be **optional** — declare them nullable with a
`null` default. The container still resolves them (the default is only a
fallback if resolution fails). This is the same convention as Laravel's queue:
data in the constructor, services at execution time.

A handler is therefore trivially unit-testable with no container at all:

```php
(new ImportCatalog(storeId: 's-1', mode: ImportMode::Full))
    ->handle($context, client: $fakeClient);
```

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
`handle()` from the constructor-bound values. Keep constructor binding flat.

### Failure modes are loud, by design

| Mistake | What happens |
| --- | --- |
| required param missing from params | refused; attempt fails with the message recorded on `job_attempts.error` / `jobs.last_error` — and `JobClass::dispatch(...)` refuses it **at the dispatch site**, before any row exists |
| invalid enum value | fails, listing the valid cases |
| unparseable / non-string date value | fails, naming the param |
| service-typed constructor param | **refused** — constructors are data-only; receive it in `handle()` |
| `Model`-typed constructor param | **refused** — pass the key, fetch in `handle()` |
| class doesn't implement `JobWardenJob` | rejected before the constructor runs |

The handler never starts in any of these cases, so a binding failure can never
half-execute a non-idempotent job. Whether the failure then retries or parks is
the normal recovery decision: `idempotent → retry within budget, else park`.

## JobContext

`JobContext` is the per-attempt capability handle — think of it as a
CancellationToken with identity and attempt-scoped operations attached. It
carries no job data (that's the constructor's job):

- `$context->jobId`, `$context->attemptId`, `$context->attemptNumber` — identity,
  useful for idempotency keys and logging.
- `$context->stopRequested()` — has the supervisor asked this run to stop?
  (see Graceful stop).
- `$context->artifact(type, name, opts)` — attach a support-case artifact
  (request/response pair, file on a disk, dump) to this attempt; bundled by
  `jobwarden:logs --export`.
- `$context->result([...])` — store the job's completion payload (see Results).
- `$context->batchId`, `$context->batch()` — for a batch member, the batch id and
  a live read of the batch around it: its state, failure policy, progress counts,
  and the members that did not succeed (with each one's error message or cancel
  reason). `null` for a standalone job. This is what a finalizer reacts to — see
  Batches in the README.

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

The engine's recovery decision reads the **`jobs.idempotent` column** (a reaper
deciding about a lost job on a dead host cannot run job code). Dispatching with
`JobClass::dispatch(...)` stamps that column **from the class declaration** —
the method is the single source of truth, never repeated at the dispatch site.
Only the raw `app(JobWarden::class)->dispatch(...)` / HTTP-API path still takes
`['idempotent' => true]` as an option; keep it consistent with the class.

## Logging

Every `Log::` facade call made during a run is captured into `job_logs`, scoped
to the attempt — viewable live in the dashboard and via `jobwarden:logs`. Write
progress markers; they are the timeline an operator sees next to the recorded
error when something parks.

### Never use the `STDOUT` / `STDERR` constants in a handler

Under the prefork execution model, the child closes those constants to reclaim
file descriptors 1/2 into the per-attempt log (that's how a fatal's "dying
words" get captured). The constants then reference **closed** streams, and
`fwrite(STDERR, …)` throws `supplied resource is not a valid stream resource`
— failing the attempt. `Log::` is the first-class channel; if you genuinely
need a raw byte stream (output a supervising process must capture even through
a crash), open the descriptor fresh:

```php
file_put_contents('php://stderr', "raw dying words\n"); // fd 2 → the attempt log under prefork
```

This works identically in both execution modes: under `exec` it's the real
stderr pipe, under `prefork` it lands in the attempt log and is drained into
`job_logs` on reap.

## Long-running jobs and graceful stop

On deploy/scale-down the supervisor sends SIGTERM and escalates to SIGKILL after
the grace window. A long-running handler cooperates by polling
`$context->stopRequested()` inside its loop:

```php
public function handle(JobContext $context): void
{
    foreach ($this->chunks() as $chunk) {
        if ($context->stopRequested()) {
            return; // checkpoint reached — exit cleanly within the grace window
        }
        $this->process($chunk);
    }
}
```

Cooperation is an optimization, never a requirement: a handler that ignores the
flag is SIGKILLed after the grace period and recorded `stopped`, and recovery
proceeds from the idempotency guard as usual. Correctness never depends on the
handler checking it. Set `max_runtime_sec` so a hung run is reaped rather than
trusted forever.

## Dispatching reference

### `JobClass::dispatch(...)` — the Dispatchable trait

Add `use JobWarden\Dispatch\Dispatchable;` to a job class and dispatch with
constructor arguments — positional (constructor order), named, or both:

```php
use JobWarden\Dispatch\Dispatchable;

final class ImportCatalog implements JobWardenJob
{
    use Dispatchable;
    // ...
}

ImportCatalog::dispatch('store-42', ImportMode::Full);
ImportCatalog::dispatch(storeId: 'store-42', mode: ImportMode::Full);

// options chain first; dispatch() is always the terminal call and returns the Job
$job = ImportCatalog::inLane('reports')
    ->delay(300)                       // seconds (DB clock) — or a DateTimeInterface
    ->priority(5)
    ->maxAttempts(3)
    ->dispatch(storeId: 'store-42', mode: ImportMode::Full);

$job->id; // the row exists — created synchronously, in a transaction
```

What the trait does for you:

- **Args become the params JSON.** Backed enums store their backing value,
  date-times store ISO-8601 with an explicit offset. Models and other objects
  are refused at the dispatch site.
- **The binding is validated before the row is created.** The params round-trip
  through the same factory the worker uses, so a dispatch that would fail to
  bind in the child fails here instead — a typo'd or missing arg never creates
  a job.
- **`idempotent()` stamps the row.** The class declaration is the single source
  of truth; you never repeat `['idempotent' => true]`.
- **No destructor commit.** Unlike Laravel's `PendingDispatch`, an unfinished
  builder chain dispatches nothing — only `dispatch()` creates the row.

Builder options: `inLane`, `delay`, `availableAt`, `priority`, `maxAttempts`,
`maxRuntime`, `named`, `idempotencyKey`, `tags`, `backoff`, `createdBy`.

Coexistence: nothing global is intercepted — Laravel's Bus/Queue `dispatch()`
behaves as ever (spec §0). A class using this trait can't also use
`Illuminate\Foundation\Bus\Dispatchable` (same method name), and
`Bus::fake()`/`Queue::fake()` don't see these dispatches — assert on the jobs
table instead.

### The service API

```php
$jw = app(JobWarden::class);

// one-off (params by key; options array — the API/schedule path)
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
