<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use JobWarden\Contracts\JobWardenJob;
use JobWarden\Runner\JobContext;
use RuntimeException;

/**
 * Burns CPU by hashing in a tight loop `cpu` times. For chaos/burn-in testing,
 * ~1 in 100 executions instead misbehaves badly — a memory hog, a file-descriptor
 * exhaustion, or a plain exception — to prove a rogue CHILD never takes down the
 * supervisor, and that crash "dying words" are captured.
 */
final class CpuJob implements JobWardenJob
{
    public function handle(JobContext $context): void
    {
        if (random_int(1, 100) === 1) {
            $this->misbehave();
        }

        $iterations = (int) ($context->params['cpu'] ?? 200_000);
        $h = 'jobwarden';
        for ($i = 0; $i < $iterations; $i++) {
            $h = hash('sha256', $h.$i);
        }
        if ($h === '') {
            throw new RuntimeException('unreachable');
        }
    }

    private function misbehave(): void
    {
        switch (random_int(1, 3)) {
            case 1:
                // MEMORY: allocate past a self-imposed cap → fatal "Allowed memory
                // size exhausted". A controlled PHP fatal, not a host-level OOM.
                ini_set('memory_limit', '384M');
                $hog = [];
                while (true) {
                    $hog[] = random_bytes(1_000_000);
                }
                // unreachable

            case 2:
                // FILE DESCRIPTORS: open handles until the OS refuses, then fail loudly.
                $fds = [];
                for ($i = 0; $i < 200_000; $i++) {
                    $f = @fopen('/dev/null', 'r');
                    if ($f === false) {
                        throw new RuntimeException("fd exhaustion: OS refused a new handle after {$i} opens");
                    }
                    $fds[] = $f;
                }
                throw new RuntimeException('fd stress: opened 200k handles without hitting the limit');

            default:
                // EXCEPTION: a plain determinate failure.
                throw new RuntimeException('chaos: deliberate exception');
        }
    }

    public function idempotent(): bool
    {
        return true;
    }
}
