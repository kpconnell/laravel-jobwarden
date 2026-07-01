<?php

declare(strict_types=1);

namespace JobWarden\Process\Fake;

use JobWarden\Process\Contracts\HostIdentity;

final class FakeHostIdentity implements HostIdentity
{
    public function __construct(
        private readonly string $hostId = 'fake-host',
        private readonly string $hostname = 'fake.local',
    ) {
    }

    public function hostId(): string
    {
        return $this->hostId;
    }

    public function hostname(): string
    {
        return $this->hostname;
    }
}
