<?php

declare(strict_types=1);

namespace JobWarden\Process\Contracts;

interface HostIdentity
{
    /** Boot-stable opaque host id (Linux: hash(machine-id : boot_id)). */
    public function hostId(): string;

    /** Human-readable only. */
    public function hostname(): string;
}
