#!/usr/bin/env bash
set -euo pipefail

# --------------------------------------------------------------------------
# machine-id provisioning — a STABLE-per-container label only.
#
# host_id is NOT a coordination key: recovery keys on worker_id (a fresh UUID the
# app mints per process start), so a restarted process is always distinguishable
# from its dead predecessor regardless of host_id. host_id is metadata + a
# locality hint the local reaper (Tier-2) uses to find and /proc-verify a box's
# own in-flight attempts — which is why it should be STABLE across a container
# restart, not churned. So we provision a machine-id only when one isn't already
# present. MACHINE_ID_SEED pins it deterministically for tests.
# --------------------------------------------------------------------------
if [ ! -s /etc/machine-id ]; then
    if [ -n "${MACHINE_ID_SEED:-}" ]; then
        printf '%s' "$MACHINE_ID_SEED" | sha256sum | cut -c1-32 > /etc/machine-id
    elif [ -r /proc/sys/kernel/random/uuid ]; then
        tr -d '-' < /proc/sys/kernel/random/uuid > /etc/machine-id
    fi
fi

# The image bakes a config cache (php artisan optimize) using BUILD-time env, which
# freezes every runtime override — JOBWARDEN_EXECUTION_MODE, JOBWARDEN_POLL_INTERVAL_MS,
# JOBWARDEN_CAPACITY, etc. all silently fall back to their baked defaults. We CLEAR it (not
# re-cache: `config:cache` re-evaluates env() against .env and would just re-freeze the
# defaults, since these overrides come from the process env, not .env). Cleared, the app
# reads config live from files + env on boot — which resolves the process env correctly.
# In prefork the long-lived master parses config once, so there is no per-job cost.
if [ -f /srv/app/artisan ]; then
    php /srv/app/artisan config:clear >/dev/null 2>&1 || true
fi

exec "$@"
