# JobWarden Operator API

A gated JSON API over JobWarden's read models and operator actions. It's the
service layer the (forthcoming Livewire) dashboard renders on top of, and it's
usable headlessly on its own.

Responses are plain Eloquent models / paginators serialized by Laravel — enum
columns come back as their string value, dates as ISO-8601, JSON columns as
objects. List endpoints return Laravel's paginator shape (`{data, links, meta}`).

## Setup

Mounted by the service provider when `jobwarden.api.enabled` is true (default).

```php
// config/jobwarden.php → 'api'
'enabled'    => true,
'prefix'     => 'jobwarden/api',   // base path
'middleware' => ['api'],           // host middleware applied before the gate
'pagination' => 50,                // default per_page (max 200)
```

### Authorization (required)

Every request passes through a gate that **defaults to `local` environment only**.
Open it up explicitly from a service provider — typically `AppServiceProvider::boot`:

```php
use JobWarden\JobWarden;

JobWarden::auth(function ($request) {
    return $request->user()?->can('viewJobWarden') ?? false;
});
```

The gate is the **security boundary**. Note that `jobs.params`, `last_error`, and
`result` contain whatever the application put there — including, potentially,
secrets or PII. They are returned as-is to any caller the gate admits, so gate
appropriately and avoid stuffing raw secrets into job params.

## Endpoints

Base path below is relative to the configured prefix (`jobwarden/api`).

### Read

| Method & path | Notes |
|---|---|
| `GET /stats` | Overview counts: jobs by state, jobs by lane, batches by state, schedule + worker counts. |
| `GET /jobs` | Paginated. Filters: `state` (repeatable), `lane`, `name`, `job_class` (exact), `created_by`, `batch_id`, `schedule_id`, `tag[name]=value` (repeatable, ANDed; trailing `*` = prefix match), `q` (token search, below), `per_page`. |
| `GET /jobs/{id}` | One job with `attempts`, `events`, `artifacts`, `tags` loaded. This is the completion-polling endpoint: watch `state`; on `succeeded` the handler's completion payload (if it stored one) is in `result`. |
| `GET /jobs/{id}/logs` | Log lines. `after=<id>` for the live-tail cursor, `limit` (default 200). |
| `GET /batches` | Paginated. Filter: `state`. |
| `GET /batches/{id}` | One batch with its member `jobs`. |
| `GET /schedules` | Paginated. Filter: `enabled`. |
| `GET /schedules/{id}` | One schedule with `recent_runs` (last 25 occurrences). |
| `GET /workers` | Registered processes (supervisors, schedulers, reapers). `all=1` to include stopped/dead, `role` to filter. |

**Tag search.** Jobs carry searchable tags (name → value strings, values ≤ 200
chars), stored in an indexed table so tag lookups never scan the jobs table.
Tags come from the dispatcher (`tags` map on `POST /jobs`) and from **param
promotion**: param names listed in `jobwarden.search.promoted_params` whose
values are strings become tags automatically at dispatch. Filter with
`?tag[storeid]=AMAZ&tag[date]=2025-01*`, or use `q`, whose whitespace-separated
tokens AND together: `name:value` matches a tag (trailing `*` = prefix, bare
`name:` = has-tag), any other token substring-matches the class or job name —
e.g. `q=storeid:AMAZ date:2025-01* Backfill`.

**Polling contract.** Dispatch returns the job id; poll `GET /jobs/{id}` until
`state` is terminal (`succeeded` | `failed` | `canceled` | `stopped`). `result`
is written **atomically with the succeeded transition**, so `state = succeeded`
guarantees the final `result` is present in the same read (null if the handler
never stored one) — no re-read needed. `result` is success-only; failures carry
their story in `last_error`.

### Job actions

| Method & path | Body / effect |
|---|---|
| `POST /jobs` | Dispatch one ad-hoc job. Body: `job_class`, optional `params`, plus dispatch options like `lane`, `idempotent`, `max_attempts`, `priority`, `available_at`, `max_runtime_sec`, `tags` (a `{name: value}` map of strings, values ≤ 200 chars — 422 otherwise). Returns 201. |

All take an optional `reason` (recorded in the audit trail) and return the fresh job.
The actor is the authenticated user id, else `api`.

| Method & path | Effect |
|---|---|
| `POST /jobs/{id}/cancel` | Desired-state cancel (pre-run → canceled; running → flagged + signaled). |
| `POST /jobs/{id}/stop` | Desired-state stop. |
| `POST /jobs/{id}/retry` | Operator retry of a failed/parked job. |
| `POST /jobs/{id}/restart` | Operator restart. |
| `POST /batches/{id}/cancel` | Cancel a batch; propagates to non-terminal members. |

### Scheduling

| Method & path | Body / effect |
|---|---|
| `POST /schedules` | Create. `name`, `cron`, `type` (`command`\|`job`), then `command` (+`arguments`) or `job_class` (+`params`); plus `idempotent`, `max_attempts`, `timezone`, `missed_policy`, `overlap_policy`. Invalid cron → 422. Returns 201. |
| `PATCH /schedules/{id}` | Update `enabled`, `cron_expression`, `idempotent`, `max_attempts`, `missed_policy`, `overlap_policy`. |
| `DELETE /schedules/{id}` | Remove the schedule (204). |
| `POST /schedules/{id}/run` | Fire immediately — dispatches an ad-hoc run onto the `scheduled` lane linked to the schedule. Returns 201 (the job). |

## Examples

```bash
# overview
curl -s localhost/jobwarden/api/stats

# orphaned (parked) jobs awaiting an operator
curl -s 'localhost/jobwarden/api/jobs?state=orphaned'

# live-tail a running job's logs
curl -s 'localhost/jobwarden/api/jobs/<id>/logs?after=0'

# dispatch a job
curl -s -XPOST localhost/jobwarden/api/jobs \
  -H 'Content-Type: application/json' \
  -d '{"job_class":"App\\Jobs\\ImportCatalog","params":{"store":"north"},"idempotent":true,"max_attempts":3}'

# retry a parked job
curl -s -XPOST 'localhost/jobwarden/api/jobs/<id>/retry' -d 'reason=transient blip cleared'

# schedule a nightly, idempotent artisan command
curl -s -XPOST localhost/jobwarden/api/schedules \
  -H 'Content-Type: application/json' \
  -d '{"name":"nightly-prune","cron":"0 3 * * *","type":"command","command":"cache:prune","idempotent":true}'

# run it now
curl -s -XPOST localhost/jobwarden/api/schedules/<id>/run
```
