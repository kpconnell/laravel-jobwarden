<?php

declare(strict_types=1);

namespace JobWarden\Supervisor;

use JobWarden\Process\Contracts\ProcessProbe;
use JobWarden\Runner\ChildRunner;
use JobWarden\Runner\ExitCode;
use JobWarden\Stamp\ProcessStampWriter;
use Illuminate\Support\Facades\DB;

/**
 * The PREFORK execution model (config jobwarden.supervisor.execution_mode = 'prefork').
 *
 * Instead of proc_open'ing a fresh `php artisan jobwarden:run` per job — a full ~144ms
 * framework boot every single time — the supervisor pcntl_fork()s from its already-booted
 * image. The child inherits the framework copy-on-write, runs ONE job in-process through
 * the same ChildRunner, and hard-exits. Per-job boot cost: ~144ms → ~0.
 *
 * Isolation is preserved, which is the whole reason to fork rather than run a persistent
 * pool: each fork is a distinct, killable PID with its own address space (a crash or leak
 * cannot touch the master or its siblings), Tier-1 waitpid reaps it, and every job starts
 * from the pristine COW baseline (no cross-job state carryover — leaks/statics/handles die
 * with the fork).
 *
 * The one real hazard is the DB socket. fork() duplicates the master's PDO file descriptor,
 * so a graceful PDO close (COM_QUIT) in the child would travel the SHARED TCP session and
 * kill the MASTER's connection. Therefore the child (a) holds a reference to every inherited
 * PDO so disconnect() only nulls the manager's slot and never drops the object to a zero
 * refcount (no destructor → no COM_QUIT), (b) reconnects fresh sockets for its own work,
 * and (c) exits via pcntl_exec so no destructor ever runs — the inherited fds are only ever
 * raw-closed by the OS. The master never touches its own connection. (Both halves of this
 * are proven by the fork-storm test.)
 */
final class ForkExecutor
{
    /**
     * Inherited PDOs held for the life of the child ON PURPOSE: keeping them referenced
     * stops disconnect() from destructing them (which would COM_QUIT the shared socket).
     *
     * @var list<\PDO>
     */
    private array $heldPdos = [];

    public function __construct(
        private readonly ProcessStampWriter $stampWriter,
        private readonly ProcessProbe $probe,
    ) {
    }

    /**
     * Fork a child to run one attempt. Returns the child PID to the PARENT; in the CHILD
     * this never returns — it runs the job and pcntl_exec's out.
     *
     * @return int child pid (>0) in the parent, or -1 if the fork failed
     */
    public function fork(string $attemptId, int $token, string $nonce, string $logFile): int
    {
        $pid = pcntl_fork();
        if ($pid > 0) {
            return $pid;   // parent
        }
        if ($pid === -1) {
            return -1;     // fork failed — caller runs failToSpawn()
        }

        // ===================== CHILD (pid === 0) =====================
        // A child must ALWAYS terminate via hardExit — never let an exception unwind back
        // through the (COW-inherited) supervisor stack. On a throw the attempt is left
        // non-terminal, so the master's Tier-1 finalize force-fails it (fencing-guarded),
        // exactly as a boot-crashed exec child would be handled.
        $code = ExitCode::FAILURE;
        try {
            $code = $this->runChild($attemptId, $token, $nonce, $logFile);
        } catch (\Throwable $e) {
            @error_log('jobwarden prefork child fatal: '.$e->getMessage());
        }
        $this->hardExit($code);   // never returns
    }

    private function runChild(string $attemptId, int $token, string $nonce, string $logFile): int
    {
        $this->resetAfterFork($logFile);
        $this->reconnectDatabase();

        // Self phase-2 stamp: this fork's OWN pid/start-time/nonce, fencing-guarded. In
        // exec mode the supervisor stamps after proc_open; here the child knows its own
        // pid immediately, shrinking the unstamped window (a reaper blind spot) to ~0.
        $pid = function_exists('posix_getpid') ? posix_getpid() : getmypid();
        $startTime = (string) ($this->probe->startTime((int) $pid) ?? '');
        $this->stampWriter->phase2($attemptId, $token, (int) $pid, $startTime, $nonce);

        return app(ChildRunner::class)->run($attemptId, $token, $nonce);
    }

    /**
     * Sever every inherited resource the fork must not share with the master. Order
     * matters: redirect stdio BEFORE reconnecting the DB, so a fresh socket cannot grab
     * the freed fd 1/2.
     */
    private function resetAfterFork(string $logFile): void
    {
        // Boot cost is ~0 for a fork — point the ChildRunner timing probe at now instead
        // of measuring the master's uptime via the inherited REQUEST_TIME_FLOAT.
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);

        // fork() copies the RNG state; reseed so children don't mint identical values.
        mt_srand();

        // Don't inherit the master's SIGTERM *drain* closure; ChildRunner installs the
        // child's own cooperative-stop handler.
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);
        }

        // Redirect this fork's raw stdout/stderr to the per-attempt log so a fatal's dying
        // words are captured (the supervisor ingests this file on reap), instead of
        // interleaving onto the master's container stdout.
        if (defined('STDOUT') && is_resource(STDOUT)) {
            fclose(STDOUT);
        }
        if (defined('STDERR') && is_resource(STDERR)) {
            fclose(STDERR);
        }
        $dir = dirname($logFile);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        fopen($logFile, 'a');   // reclaims fd 1
        fopen($logFile, 'a');   // reclaims fd 2
    }

    /**
     * Give the child its OWN connections without letting the inherited ones tear down the
     * shared socket. Holding a reference to each inherited PDO means disconnect() only
     * nulls the manager's slot — the object never hits refcount 0, so no destructor
     * (COM_QUIT) fires. The held PDOs die with the process at pcntl_exec, which closes
     * their fds raw. The next query on each connection lazily opens a fresh socket.
     */
    private function reconnectDatabase(): void
    {
        foreach (DB::getConnections() as $conn) {
            foreach (['getRawPdo', 'getRawReadPdo'] as $getter) {
                if (method_exists($conn, $getter)) {
                    $pdo = $conn->{$getter}();
                    if ($pdo instanceof \PDO) {
                        $this->heldPdos[] = $pdo;
                    }
                }
            }
            $conn->disconnect();
        }
    }

    /**
     * Terminate WITHOUT running PHP shutdown/destructors — the only safe way to exit a
     * fork whose inherited PDOs must never emit COM_QUIT on the master's shared socket.
     * pcntl_exec replaces the process image, so no destructor runs and all fds are closed
     * raw by the OS. The outcome is already authoritative in the DB; a real crash still
     * surfaces as a term-signal (not a clean exit), which is what the reaper keys on.
     */
    private function hardExit(int $code): never
    {
        // Flush any buffered output to fd 1 (the redirected attempt log). NB: do NOT
        // fflush(STDOUT) — resetAfterFork closed that constant to reclaim the fd, so
        // fflush() on it would TypeError (which '@' can't suppress) and abort the exit.
        @flush();
        // pcntl_exec only returns on failure (e.g. an incoming signal EINTR'ing execve),
        // so retry a few times; the '@' keeps a failed attempt from tripping an inherited
        // error handler. As a last resort SIGKILL ourselves — still destructor-free, unlike
        // exit(), which would fire the inherited PDO destructor and COM_QUIT the master's
        // shared socket.
        for ($i = 0; $i < 8; $i++) {
            @pcntl_exec('/bin/true');
        }
        posix_kill((int) (function_exists('posix_getpid') ? posix_getpid() : getmypid()), SIGKILL);
        exit($code); // unreachable
    }
}
