<?php

declare(strict_types=1);

namespace JobWarden\Claim;

use Illuminate\Database\Connection;
use PDO;

/**
 * Detects the database engine and whether it supports
 * `SELECT … FOR UPDATE SKIP LOCKED` (spec §2): PostgreSQL ≥ 9.5,
 * MySQL ≥ 8.0.1, MariaDB ≥ 10.6. Everything else (incl. SQLite) → false.
 */
final class EngineInspector
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function driver(): string
    {
        return $this->connection->getDriverName();
    }

    public function serverVersion(): string
    {
        return (string) $this->connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    public function isMariaDb(): bool
    {
        return stripos($this->serverVersion(), 'mariadb') !== false;
    }

    public function supportsSkipLocked(): bool
    {
        $version = $this->numericVersion($this->serverVersion());

        return match ($this->driver()) {
            'pgsql' => version_compare($version, '9.5', '>='),
            'mysql' => $this->isMariaDb()
                ? version_compare($version, '10.6', '>=')
                : version_compare($version, '8.0.1', '>='),
            default => false,
        };
    }

    /**
     * Extract the real numeric version. MariaDB ≥10 prefixes "5.5.5-" to its
     * version string (e.g. "5.5.5-10.11.6-MariaDB") to fool ancient clients that
     * can't parse a major version ≥10 — RDS MariaDB does this. Strip that prefix
     * first, or we'd read "5.5.5" and wrongly conclude no SKIP LOCKED.
     */
    private function numericVersion(string $raw): string
    {
        $raw = preg_replace('/^5\.5\.5-/', '', $raw);

        if (preg_match('/(\d+\.\d+(?:\.\d+)?)/', $raw, $m) === 1) {
            return $m[1];
        }

        return '0';
    }
}
