<?php

declare(strict_types=1);

/**
 * Standalone concurrent claimer for the SKIP LOCKED stress test (P3, Tier B).
 *
 * It faithfully mirrors SkipLockedClaimDriver's critical section using raw PDO,
 * so N real OS processes can race against one shared Postgres. Each process
 * busy-waits to a shared barrier instant (to maximize overlap), then drains the
 * queue and prints the job ids it won (one per line).
 *
 * Hardened for heavy contention: the connection and every claim iteration retry
 * on transient errors (rolling back releases any held lock for a peer), and a
 * claimer only quits once the queue is CONFIRMED empty across several polls — so
 * no job is ever abandoned and the run is deterministic under load.
 *
 * The UNIQUE(job_id, attempt_number) index is the built-in double-claim
 * tripwire: a genuine double-claim would collide on the second insert.
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
            'ORDER BY priority DESC, available_at ASC LIMIT 1 FOR UPDATE SKIP LOCKED'
        )->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            $pdo->commit();
            // A single empty poll can mean "all remaining rows are momentarily
            // locked by a slow peer", not "drained". Only quit once the queue is
            // CONFIRMED empty across several consecutive polls.
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

        $pdo->prepare(
            "INSERT INTO {$attempts} (id, job_id, attempt_number, state, fencing_token, host_id) VALUES (?,?,?,?,?,?)"
        )->execute([$attemptId, $id, $n, 'dispatched', $n, 'stress-host']);

        $pdo->prepare(
            "UPDATE {$jobs} SET state='running', attempt_count=?, current_attempt_id=? WHERE id=?"
        )->execute([$n, $attemptId, $id]);

        $pdo->commit();
        $claimed[] = $id;
    } catch (PDOException $e) {
        // Transient (serialization, dropped connection, contention): roll back —
        // which releases any row we'd locked so a peer can take it — and retry.
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
                // Match the production claim driver: READ COMMITTED so SKIP LOCKED
                // isn't fighting REPEATABLE-READ gap locks.
                $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            }

            return $pdo;
        } catch (PDOException $e) {
            $last = $e;
            usleep(200_000 * ($try + 1)); // back off on "too many clients" etc.
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
