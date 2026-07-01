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
| `GET /jobs` | Paginated. Filters: `state` (repeatable), `lane`, `name`, `created_by`, `batch_id`, `schedule_id`, `q` (job_class substring), `per_page`. |
| `GET /jobs/{id}` | One job with `attempts`, `events`, `artifacts` loaded. |
| `GET /jobs/{id}/logs` | Log lines. `after=<id>` for the live-tail cursor, `limit` (default 200). |
| `GET /batches` | Paginated. Filter: `state`. |
| `GET /batches/{id}` | One batch with its member `jobs`. |
| `GET /schedules` | Paginated. Filter: `enabled`. |
| `GET /schedules/{id}` | One schedule with `recent_runs` (last 25 occurrences). |
| `GET /workers` | Registered processes (supervisors, schedulers, reapers). `all=1` to include stopped/dead, `role` to filter. |

### Job actions

| Method & path | Body / effect |
|---|---|
| `POST /jobs` | Dispatch one ad-hoc job. Body: `job_class`, optional `params`, plus dispatch options like `lane`, `idempotent`, `max_attempts`, `priority`, `available_at`, `max_runtime_sec`, `tags`. Returns 201. |

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
