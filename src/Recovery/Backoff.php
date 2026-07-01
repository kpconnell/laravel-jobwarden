<?php

declare(strict_types=1);

namespace JobWarden\Recovery;

final class Backoff
{
    public static function seconds(string $strategy, int $attempt, int $base, int $cap): int
    {
        $value = match ($strategy) {
            'fixed' => $base,
            'exponential' => $base * (2 ** max(0, $attempt - 1)),
            default => $base * max(1, $attempt),
        };

        return (int) min($value, $cap);
    }

    public static function fromConfig(int $attempt, ?string $strategy = null): int
    {
        $cfg = (array) config('jobwarden.retry.backoff');

        return self::seconds(
            $strategy ?? (string) ($cfg['strategy'] ?? 'exponential'),
            $attempt,
            (int) ($cfg['base'] ?? 5),
            (int) ($cfg['cap'] ?? 300),
        );
    }
}
