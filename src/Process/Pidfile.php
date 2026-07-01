<?php

declare(strict_types=1);

namespace JobWarden\Process;

/**
 * The authoritative proc_nonce channel (spec §5.3): the child writes a pidfile
 * keyed by attempt_id; the local reaper reads it to confirm *this attempt's*
 * process. Host-local only — Tier 3 never reads it. (proctitle is a secondary,
 * best-effort channel.)
 */
final class Pidfile
{
    public function __construct(private readonly string $runtimePath)
    {
    }

    public function path(string $attemptId): string
    {
        return $this->runtimePath.'/pids/attempt-'.$attemptId.'.pid';
    }

    /** @param array{pid:int,start_time:?string,nonce:string,token:int} $data */
    public function write(string $attemptId, array $data): void
    {
        $path = $this->path($attemptId);
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        file_put_contents($path, json_encode($data + ['attempt_id' => $attemptId], JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed>|null */
    public function read(string $attemptId): ?array
    {
        $path = $this->path($attemptId);
        if (! is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    public function delete(string $attemptId): void
    {
        $path = $this->path($attemptId);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
