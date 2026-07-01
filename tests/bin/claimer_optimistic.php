<?php

declare(strict_types=1);

/**
 * Standalone concurrent claimer for the OPTIMISTIC driver stress test (P9).
 *
 * Mirrors OptimisticClaimDriver's critical section with raw PDO: SELECT a queued
 * candidate WITHOUT a lock, then claim it with a guarded UPDATE … WHERE state='queued'.
 * Exactly one racer's UPDATE affects the row; the losers see affected==0 and retry.
 * Proves the lock-free path also never double-claims, on Postgres AND MySQL/MariaDB.
 *
 * The UNIQUE(job_id, attempt_number) index is the built-in double-claim tripwire.
 */

$driver = getenv('JOBWARDEN_DRIVER') ?: 'pgsql';
$host = getenv('JOBWARDEN_DB_HOST') ?: '127.0.0.1';
$port = getenv('JOBWARDEN_DB_PORT') ?: '5432';
$db = getenv('JOBWARDEN_DB_NAME') ?: 'jobwarden';
$user = getenv('JOBWARDEN_DB_USER') ?: 'jobwarden';
$pass = getenv('JOBWARDEN_DB_PASSWORD') ?: 'secret';
$prefix = getenv('JOBWARDEN_PREFIX') ?: 'jobwarden_';
$barrier = (float) (getenv('JOBWARDEN_BARRIER') ?: '0');

$jobs = $prefix.'jobs';
$attempts = $prefix.'job_attempts';

$pdo = connectWithRetry($driver, $host, $port, $db, $user, $pass);

while (microtime(true) < $barrier) {
    usleep(200);
}

$claimed = [];
$emptyPolls = 0;
$iterations = 0;

while ($iterations++ < 100_000) {
    try {
        $pdo->beginTransaction();

        $row = $pdo->query(
            "SELECT id, attempt_count FROM {$jobs} ".
            "WHERE lane='default' AND state='queued' AND (available_at IS NULL OR available_at <= CURRENT_TIMESTAMP) ".
            'ORDER BY priority DESC, available_at ASC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            $pdo->commit();
            if (++$emptyPolls >= 12) {
                break;
            }
            usleep(25_000);

            continue;
        }

        $emptyPolls = 0;
        $id = $row['id'];
        $n = ((int) $row['attempt_count']) + 1;
        $attemptId = guidv4();

        // The guarded claim: only the racer whose UPDATE matches state='queued' wins.
        $upd = $pdo->prepare("UPDATE {$jobs} SET state='running', attempt_count=?, current_attempt_id=? WHERE id=? AND state='queued'");
        $upd->execute([$n, $attemptId, $id]);

        if ($upd->rowCount() !== 1) {
            // Lost the race — a peer claimed this row first.
            $pdo->rollBack();
            usleep(2_000);

            continue;
        }

        $pdo->prepare(
            "INSERT INTO {$attempts} (id, job_id, attempt_number, state, fencing_token, host_id) VALUES (?,?,?,?,?,?)"
        )->execute([$attemptId, $id, $n, 'dispatched', $n, 'stress-host']);

        $pdo->commit();
        $claimed[] = $id;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (\Throwable) {
            }
        }
        $emptyPolls = 0;
        usleep(20_000);
    }
}

echo implode("\n", $claimed);
if ($claimed !== []) {
    echo "\n";
}

function connectWithRetry(string $driver, string $host, string $port, string $db, string $user, string $pass): PDO
{
    $dsn = $driver === 'mysql'
        ? "mysql:host={$host};port={$port};dbname={$db}"
        : "pgsql:host={$host};port={$port};dbname={$db}";
    $last = null;
    for ($try = 0; $try < 6; $try++) {
        try {
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if ($driver === 'mysql') {
                $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            }

            return $pdo;
        } catch (PDOException $e) {
            $last = $e;
            usleep(200_000 * ($try + 1));
        }
    }

    fwrite(STDERR, 'claimer could not connect: '.($last?->getMessage() ?? 'unknown')."\n");
    exit(2);
}

function guidv4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
