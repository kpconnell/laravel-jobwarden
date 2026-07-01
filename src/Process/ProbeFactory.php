<?php

declare(strict_types=1);

namespace JobWarden\Process;

use JobWarden\Process\Contracts\ProcessProbe;
use JobWarden\Process\Fake\FakeProbe;
use RuntimeException;

final class ProbeFactory
{
    public function make(): ProcessProbe
    {
        $configured = (string) config('jobwarden.process.probe', 'auto');
        $runtimePath = (string) config('jobwarden.runtime_path');

        return match ($configured) {
            'fake' => new FakeProbe,
            'linux' => new LinuxProcProbe(new Pidfile($runtimePath)),
            default => $this->auto($runtimePath),
        };
    }

    private function auto(string $runtimePath): ProcessProbe
    {
        if (PHP_OS_FAMILY === 'Linux') {
            return new LinuxProcProbe(new Pidfile($runtimePath));
        }

        throw new RuntimeException(
            'JobWarden targets Linux for process verification. Run inside the Docker Linux stack, '.
            "or set jobwarden.process.probe = 'fake' for unit tests."
        );
    }
}
