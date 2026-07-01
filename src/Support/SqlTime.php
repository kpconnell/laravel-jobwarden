<?php

declare(strict_types=1);

namespace JobWarden\Support;

use Illuminate\Database\Connection;

/**
 * Driver-portable "DB clock ± N seconds" SQL fragments. ALL lease/heartbeat
 * comparisons use the DB clock (spec §5.3) to avoid cross-host skew, so these
 * are evaluated server-side, never with PHP time().
 */
final class SqlTime
{
    public static function nowPlus(Connection $conn, int $seconds): string
    {
        return self::expr($conn, $seconds);
    }

    public static function nowMinus(Connection $conn, int $seconds): string
    {
        return self::expr($conn, -$seconds);
    }

    private static function expr(Connection $conn, int $seconds): string
    {
        $sign = $seconds >= 0 ? '+' : '-';
        $abs = abs($seconds);

        return match ($conn->getDriverName()) {
            'pgsql' => "CURRENT_TIMESTAMP {$sign} interval '{$abs} seconds'",
            'sqlite' => "datetime('now', '{$sign}{$abs} seconds')",
            'mysql', 'mariadb' => "DATE_ADD(CURRENT_TIMESTAMP, INTERVAL {$seconds} SECOND)",
            default => 'CURRENT_TIMESTAMP',
        };
    }
}
