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

exec "$@"
