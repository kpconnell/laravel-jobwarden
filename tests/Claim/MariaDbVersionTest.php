<?php

declare(strict_types=1);

namespace JobWarden\Tests\Claim;

use JobWarden\Claim\EngineInspector;
use Illuminate\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The MariaDB "5.5.5-" version-string prefix (used by RDS MariaDB) must not fool
 * SKIP-LOCKED detection. Pure unit test over the version parser — no DB needed.
 */
final class MariaDbVersionTest extends TestCase
{
    private function numericVersion(string $raw): string
    {
        $inspector = new EngineInspector($this->createStub(Connection::class));
        $m = new ReflectionMethod($inspector, 'numericVersion');

        return $m->invoke($inspector, $raw);
    }

    public function test_strips_the_legacy_5_5_5_prefix_that_rds_mariadb_emits(): void
    {
        $this->assertSame('10.11.6', $this->numericVersion('5.5.5-10.11.6-MariaDB-1:10.11.6+maria~ubu2204'));
        $this->assertSame('10.6.16', $this->numericVersion('5.5.5-10.6.16-MariaDB'));
    }

    public function test_handles_unprefixed_strings_too(): void
    {
        $this->assertSame('11.4.12', $this->numericVersion('11.4.12-MariaDB-ubu2404'));
        $this->assertSame('8.0.39', $this->numericVersion('8.0.39'));
        $this->assertSame('18.0', $this->numericVersion('18.0 (Debian)'));
    }

    public function test_a_5_5_5_prefixed_mariadb_is_recognized_as_skip_locked_capable(): void
    {
        $m = new ReflectionMethod(EngineInspector::class, 'numericVersion');
        // 5.5.5-10.6.x must resolve to 10.6.x ≥ 10.6 (the MariaDB SKIP LOCKED floor).
        $this->assertTrue(version_compare($this->numericVersion('5.5.5-10.6.16-MariaDB'), '10.6', '>='));
        // Without the strip it would read 5.5.5 and (wrongly) fail this.
        $this->assertFalse(version_compare('5.5.5', '10.6', '>='));
    }
}
