<?php

declare(strict_types=1);

namespace JobWarden\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;

/**
 * Driver-portable "DB clock" helpers. ALL lease / heartbeat / availability comparisons
 * use the DB clock (spec §5.3) to avoid cross-host skew and app↔DB timezone drift, so
 * they are evaluated server-side, never with PHP time().
 *
 * They also honor Carbon::setTestNow(): the DB clock itself can't be time-traveled, so
 * when a test freezes the clock these substitute a literal in that frame (which is what
 * lets time-travel tests exercise availability/backoff). In production — no test now —
 * everything resolves to CURRENT_TIMESTAMP.
 */
final class SqlTime
{
    /**
     * The DB's current instant as an absolute Carbon, fetched TZ-safely (as a unix epoch)
     * so it compares correctly regardless of the app or DB session timezone. For the rare
     * PHP-side computation (e.g. resolving a delay); prefer nowExpr()/nowPlus() for writes
     * and SQL comparisons.
     */
    public static function now(Connection $conn): Carbon
    {
        if (Carbon::hasTestNow()) {
            return Carbon::now(); // the frozen test clock, as Illuminate\Support\Carbon
        }

        $epoch = match ($conn->getDriverName()) {
            'pgsql' => 'EXTRACT(EPOCH FROM CURRENT_TIMESTAMP)',
            'sqlite' => "CAST(strftime('%s', 'now') AS INTEGER)",
            default => 'UNIX_TIMESTAMP()', // mysql / mariadb
        };

        $row = $conn->selectOne("SELECT {$epoch} AS epoch");

        return Carbon::createFromTimestamp((int) ($row->epoch ?? 0));
    }

    /** A SQL fragment for the DB clock's "now" — a literal in the frozen frame under setTestNow. */
    public static function nowExpr(Connection $conn): string
    {
        return Carbon::hasTestNow()
            ? "'".Carbon::getTestNow()->format('Y-m-d H:i:s.u')."'"
            : 'CURRENT_TIMESTAMP';
    }

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
        // Under setTestNow, resolve to a fixed literal in the frozen frame (no per-driver
        // interval math) so time-travel tests work against the DB clock.
        if (Carbon::hasTestNow()) {
            return "'".Carbon::getTestNow()->copy()->addSeconds($seconds)->format('Y-m-d H:i:s.u')."'";
        }

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
