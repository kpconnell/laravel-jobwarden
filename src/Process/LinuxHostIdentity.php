<?php

declare(strict_types=1);

namespace JobWarden\Process;

use JobWarden\Process\Contracts\HostIdentity;
use RuntimeException;

/**
 * Boot-stable host identity from machine-id + boot_id (spec §5.3). machine-id
 * survives hostname reuse on a reimaged box; boot_id changes on reboot so a
 * rebooted host cannot masquerade as its pre-reboot self.
 *
 * machine-id / boot_id are injectable for testing.
 */
final class LinuxHostIdentity implements HostIdentity
{
    private ?string $cached = null;

    public function __construct(
        private readonly ?string $machineId = null,
        private readonly ?string $bootId = null,
    ) {
    }

    public function hostId(): string
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $machineId = $this->machineId ?? $this->readFirst(['/etc/machine-id', '/var/lib/dbus/machine-id']);
        $bootId = $this->bootId ?? $this->read('/proc/sys/kernel/random/boot_id');

        if ($machineId === null) {
            throw new RuntimeException(
                'JobWarden cannot read /etc/machine-id (nor /var/lib/dbus/machine-id). '.
                'A boot-stable machine-id is required for host identity.'
            );
        }

        return $this->cached = hash('sha256', trim($machineId).':'.trim((string) $bootId));
    }

    public function hostname(): string
    {
        $hostname = gethostname();

        return $hostname === false ? 'unknown' : $hostname;
    }

    /** @param string[] $paths */
    private function readFirst(array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = $this->read($path);
            if ($value !== null && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    private function read(string $path): ?string
    {
        if (! is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }
}
