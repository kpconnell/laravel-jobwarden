<?php

declare(strict_types=1);

namespace JobWarden\Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use JobWarden\Support\SqlTime;
use JobWarden\Tests\TestCase;
use Throwable;

/**
 * The DB-clock read path must recover the true absolute instant even when every
 * timezone knob we do NOT control is set to a different lie:
 *
 *     app.timezone            (consuming app)  = UTC-4
 *     @@global.time_zone      (MariaDB server) = UTC-5
 *     @@session.time_zone     (client)         = UTC-6
 *
 * SqlTime::now() reads UNIX_TIMESTAMP() — an absolute epoch MariaDB computes
 * independent of session/global tz — so the Carbon it returns is anchored to the
 * real instant regardless of the three offsets. We prove it against an EXTERNAL
 * time source (an HTTPS `Date` header, RFC 7231 GMT), never the local machine
 * clock, which could itself be wrong.
 *
 * Companion to DbClockConsistencyTest (which guards the WRITE side). MariaDB-only:
 * it exercises MySQL TIMESTAMP/UNIX_TIMESTAMP semantics and needs a privileged
 * (root) connection to move @@global.time_zone. Skips cleanly when unavailable.
 */
final class SqlTimeTimezoneGauntletTest extends TestCase
{
    /** Absorbs fetch/setup latency and the helper's whole-second epoch truncation. */
    private const TOLERANCE_SECONDS = 5;

    private ?string $originalDefaultTz = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array($this->engine(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The timezone gauntlet exercises MariaDB session/global tz semantics.');
        }

        $this->originalDefaultTz = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        if ($this->originalDefaultTz !== null) {
            date_default_timezone_set($this->originalDefaultTz);
        }

        parent::tearDown();
    }

    public function test_db_clock_recovers_true_utc_through_triple_tz_misconfiguration(): void
    {
        // 1) Authoritative UTC from an external time server, BEFORE we touch any tz.
        $trueUtc = $this->utcFromTimeServer();

        // 2) App/PHP timezone = UTC-4. Etc/GMT is POSIX-inverted, so +4 means UTC-4.
        config(['app.timezone' => 'Etc/GMT+4']);
        date_default_timezone_set('Etc/GMT+4');

        $conn = DB::connection('jobwarden');
        $root = $this->rootConnection();

        // 3) MariaDB server (global) timezone = UTC-5. Needs SUPER, hence the root conn.
        $originalGlobal = (string) $root->selectOne('SELECT @@global.time_zone AS tz')->tz;
        $root->statement("SET GLOBAL time_zone = '-05:00'");

        try {
            // 4) MariaDB session timezone on OUR connection = UTC-6 (client misconfig).
            $conn->statement("SET SESSION time_zone = '-06:00'");

            // Guard: the three layers really are three different, wrong offsets.
            $this->assertSame('-06:00', (string) $conn->selectOne('SELECT @@session.time_zone AS tz')->tz);
            $this->assertSame('-05:00', (string) $root->selectOne('SELECT @@global.time_zone AS tz')->tz);
            $this->assertSame('Etc/GMT+4', config('app.timezone'));

            // 5) Read the DB clock through our helper and normalize to UTC.
            $dbUtc = SqlTime::now($conn)->utc();
        } finally {
            // Shared dev server: restore the global tz no matter what happened above.
            $root->statement("SET GLOBAL time_zone = '{$originalGlobal}'");
        }

        // 6) The helper recovered the true instant despite -4 / -5 / -6 all lying.
        $drift = abs($dbUtc->diffInSeconds($trueUtc));
        $this->assertLessThanOrEqual(
            self::TOLERANCE_SECONDS,
            $drift,
            "DB clock via SqlTime::now() drifted {$drift}s from external UTC "
            ."(db={$dbUtc->toIso8601String()}, true={$trueUtc->toIso8601String()})."
        );
    }

    /** True UTC from an HTTPS `Date` header (RFC 7231 GMT) — not the local machine clock. */
    private function utcFromTimeServer(): Carbon
    {
        foreach (['https://www.google.com', 'https://www.cloudflare.com'] as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY => true,          // HEAD
                CURLOPT_HEADER => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $raw = curl_exec($ch);

            if (is_string($raw) && preg_match('/^Date:\s*(.+)$/mi', $raw, $m)) {
                return Carbon::parse(trim($m[1]))->utc();
            }
        }

        $this->markTestSkipped('No external time server reachable to establish authoritative UTC.');
    }

    /** A privileged connection (mirrors the jobwarden config with root creds) to move @@global.time_zone. */
    private function rootConnection(): Connection
    {
        $config = config('database.connections.jobwarden');
        $config['username'] = getenv('JOBWARDEN_DB_ROOT_USER') ?: 'root';
        $config['password'] = getenv('JOBWARDEN_DB_ROOT_PASSWORD') ?: 'rootsecret';

        config(['database.connections.jobwarden_root' => $config]);
        DB::purge('jobwarden_root');

        try {
            $conn = DB::connection('jobwarden_root');
            $conn->getPdo(); // fail fast if root can't connect / isn't provisioned
        } catch (Throwable $e) {
            $this->markTestSkipped('No privileged (root) MariaDB connection available: '.$e->getMessage());
        }

        return $conn;
    }
}
