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
 *
 * Fork safety is a three-tier contract (docs/HOSTING.md "Fork safety"):
 *   1. JobWarden's own machinery — DB connection, stdio, signals, RNG, timing — is reset
 *      here unconditionally.
 *   2. Framework services with well-known container keys (config
 *      jobwarden.supervisor.prefork_forget) are dropped so the child rebuilds them fresh
 *      on first use; the inherited instances are held so no destructor can say goodbye
 *      in-band on a socket shared with the master.
 *   3. Everything the container can't name is the app's, via the PreforkChildStarting
 *      event dispatched just before the handler runs.
 */
final class ForkExecutor
{
    /**
     * The shipped prefork_forget list (config/jobwarden.php references this) — the
     * connection-holding framework managers plus the framework singletons known to
     * capture one of them at construction. Also the in-code fallback when a published
     * config predates the key.
     *
     * @var list<string>
     */
    public const DEFAULT_FORGET = [
        'redis',
        'cache',
        'cache.store',
        'cache.psr6',
        \Illuminate\Cache\RateLimiter::class,
        'queue',
        'queue.connection',
        'filesystem',
        'mail.manager',
        'log',
    ];

    /**
     * Inherited PDOs held for the life of the child ON PURPOSE: keeping them referenced
     * stops disconnect() from destructing them (which would COM_QUIT the shared socket).
     *
     * @var list<\PDO>
     */
    private array $heldPdos = [];

    /**
     * Inherited framework-service instances (the prefork_forget list) held for the life
     * of the child ON PURPOSE, same reasoning as the PDOs: forgetting a binding could
     * drop its instance to refcount 0, and a client destructor that says goodbye
     * in-band (a QUIT command, a TLS close_notify) would travel the socket SHARED with
     * the master. Held instances die raw at pcntl_exec.
     *
     * @var list<object>
     */
    private array $heldServices = [];

    /**
     * The child's reopened stdout/stderr, held for the life of the process ON PURPOSE:
     * an unassigned fopen() is refcount-freed at the end of the statement, which closes
     * the descriptor again and leaves fd 1/2 free for the NEXT open — the child's fresh
     * DB socket — to claim. See resetAfterFork.
     *
     * @var resource|null
     */
    private mixed $stdout = null;

    /** @var resource|null */
    private mixed $stderr = null;

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
            // The ONLY record of an exception that escapes ChildRunner — the attempt row
            // carries no error, since only ChildRunner writes that — so record the throw
            // site and trace, not just the message.
            //
            // Written to the REDIRECTED handle, not error_log(): error_log() goes through
            // the SAPI logger's libc stderr, which resetAfterFork closed and which does
            // NOT recover when fd 2 is reopened underneath it. This lands in the attempt
            // log, which the supervisor ingests into job_logs on reap.
            if (is_resource($this->stderr)) {
                @fwrite($this->stderr,
                    'jobwarden prefork child fatal: '.$e::class.': '.$e->getMessage()
                    .' @ '.$e->getFile().':'.$e->getLine()."\n".$e->getTraceAsString()."\n"
                );
            }
        }
        $this->hardExit($code);   // never returns
    }

    private function runChild(string $attemptId, int $token, string $nonce, string $logFile): int
    {
        $this->resetAfterFork($logFile);
        $this->forgetFrameworkServices();
        $this->reconnectDatabase();

        // Self phase-2 stamp: this fork's OWN pid/start-time/nonce, fencing-guarded. In
        // exec mode the supervisor stamps after proc_open; here the child knows its own
        // pid immediately, shrinking the unstamped window (a reaper blind spot) to ~0.
        $pid = function_exists('posix_getpid') ? posix_getpid() : getmypid();
        $startTime = (string) ($this->probe->startTime((int) $pid) ?? '');
        $this->stampWriter->phase2($attemptId, $token, (int) $pid, $startTime, $nonce);

        // Tier 3 of the fork-safety contract: the app's after-fork hook, fired after
        // everything JobWarden owns is severed and immediately before the handler.
        // The host log channel is silenced FIRST (the same step ChildRunner takes):
        // 'log' was just forgotten, so a listener's first Log:: call builds a fresh
        // manager — which must not land on the host's default channel (a file, a
        // remote sink, php://stderr → this attempt's log) or throw from it. Job-log
        // capture into job_logs is installed later by ChildRunner; listener log lines
        // are silenced, not captured (documented in HOSTING "Fork safety").
        ChildRunner::silenceHostLogChannel();
        event(new \JobWarden\Events\PreforkChildStarting($attemptId, (int) $pid));

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
        // MUST be held (see the properties): a discarded fopen() handle is freed — and
        // its fd closed — at the end of the statement, so fd 1/2 would fall right back
        // open for the DB reconnect below to take. The child would then have no
        // stdout/stderr (a fatal's dying words vanish) AND every write to
        // php://stdout/php://stderr would land in its database socket.
        $this->stdout = fopen($logFile, 'a');   // reclaims fd 1
        $this->stderr = fopen($logFile, 'a');   // reclaims fd 2
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
     * Tier 2 of the fork-safety contract: drop the connection-holding framework services
     * (config jobwarden.supervisor.prefork_forget) so the child's first use of each
     * rebuilds a fresh instance — its own sockets, not the master's. Only bindings the
     * master ACTUALLY resolved are touched: resolving one here just to forget it would
     * boot a service the app never used, inside every fork.
     *
     * The inherited instance is held (see $heldServices) before the binding is dropped,
     * so no destructor can fire a graceful goodbye down a shared socket.
     */
    private function forgetFrameworkServices(): void
    {
        $app = app();
        $bindings = $app->getBindings();
        // `??` not a config default: a config published before 1.17 replaces the whole
        // 'supervisor' block (mergeConfigFrom is a shallow merge), and a missing key
        // must fall back to the shipped list, never to "forget nothing" silently.
        $keys = config('jobwarden.supervisor.prefork_forget') ?? self::DEFAULT_FORGET;

        foreach ((array) $keys as $abstract) {
            if (! is_string($abstract) || $abstract === '') {
                continue;
            }
            // Normalize container aliases FIRST: resolved() alias-resolves its argument
            // but forgetInstance() is a bare unset on the base key, so an aliased entry
            // (e.g. CacheManager::class for 'cache') would pass the guard and then
            // silently forget nothing.
            $abstract = $app->getAlias($abstract);

            // Only a SHARED binding the master actually resolved holds a cached instance
            // to drop, and only a binding with a concrete (singleton()/bind(), not a
            // bare instance()) can be REBUILT in the child — an instance() registration
            // has nothing to rebuild from, so forgetting it would make the child's first
            // use autowire a different object or throw; those are hook territory and are
            // skipped here. resolved() alone is not enough either: a plain bind() marks
            // resolved but caches nothing, so make() would CONSTRUCT a fresh instance
            // inside every fork just to discard it.
            if (! $app->resolved($abstract) || ! $app->isShared($abstract) || ! isset($bindings[$abstract])) {
                continue;
            }

            $instance = $app->make($abstract);
            if (is_object($instance)) {
                $this->heldServices[] = $instance;
            }
            $app->forgetInstance($abstract);
        }

        // The container is not the only cache: facades keep the resolved root in a
        // static (inherited copy-on-write) keyed by the facade's ACCESSOR string, which
        // may be the base key, an alias, or a contract name — it cannot be derived from
        // the forget list. So clear every facade's cached root, as Octane does: a facade
        // over an untouched service simply re-resolves the same container instance on
        // its next call, while Cache::/Redis::/Log:: over a forgotten one stop serving
        // the MASTER'S inherited manager — and its sockets.
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
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
        //
        // The exec'd image's status IS this child's exit status, so the code has to be
        // carried by the thing we exec. '/bin/true' reported a clean 0 for every outcome,
        // including a child that died mid-flight — which the supervisor then renders to an
        // operator as "child exited with code 0 without reporting", the one signal that
        // would have distinguished an escaped exception from an unreported success.
        // /bin/true remains the fallback (and macOS has no /bin/true, only /usr/bin/true,
        // so the fallback chain matters for dev boxes too).
        foreach (['/bin/sh', '/bin/true', '/usr/bin/true'] as $image) {
            for ($i = 0; $i < 8; $i++) {
                @pcntl_exec($image, $image === '/bin/sh' ? ['-c', 'exit '.$code] : []);
            }
        }
        posix_kill((int) (function_exists('posix_getpid') ? posix_getpid() : getmypid()), SIGKILL);
        exit($code); // unreachable
    }
}
