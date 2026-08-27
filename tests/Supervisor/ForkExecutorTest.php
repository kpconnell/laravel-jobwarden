<?php

declare(strict_types=1);

namespace JobWarden\Tests\Supervisor;

use JobWarden\Process\Contracts\ProcessProbe;
use JobWarden\Runner\ExitCode;
use JobWarden\Stamp\ProcessStampWriter;
use JobWarden\Supervisor\ForkExecutor;
use JobWarden\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

/** A facade whose accessor is a container ALIAS of the real binding ('wb.base'). */
final class AliasAccessorFacade extends \Illuminate\Support\Facades\Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'wb.alias-name';
    }
}

/**
 * The prefork child's process-level contract, exercised in REAL forks (the only
 * place it's observable — both behaviours mutate the calling process's own file
 * descriptors and exit status).
 *
 * No database: each child runs one private ForkExecutor method and pcntl_exec's
 * straight out, so the test process's connection is never inherited into a child
 * that could tear it down.
 */
final class ForkExecutorTest extends TestCase
{
    private string $runtime;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('prefork requires the pcntl extension.');
        }

        $this->runtime = sys_get_temp_dir().'/jobwarden-fork-'.bin2hex(random_bytes(4));
        @mkdir($this->runtime, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) @glob($this->runtime.'/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->runtime);

        parent::tearDown();
    }

    private function executor(): ForkExecutor
    {
        return new ForkExecutor(
            $this->app->make(ProcessStampWriter::class),
            $this->app->make(ProcessProbe::class),
        );
    }

    /**
     * REGRESSION (field report, prefork cutover): resetAfterFork closed the inherited
     * stdout/stderr and reopened them onto the attempt log with the fopen() handles
     * DISCARDED. PHP frees an unassigned resource at the end of the statement, closing
     * the descriptor again — so the child ran with fd 1/2 shut, and the next descriptor
     * it opened (its own fresh DB socket) was handed fd 1. Two casualties: the child's
     * dying words went nowhere, and anything writing to php://stderr wrote into the
     * database socket.
     */
    public function test_a_forked_child_keeps_stdout_and_stderr_on_the_attempt_log(): void
    {
        $log = $this->runtime.'/attempt-fd.log';
        $rival = $this->runtime.'/rival.bin';

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'could not fork');

        if ($pid === 0) {
            $executor = $this->executor();
            (new ReflectionMethod(ForkExecutor::class, 'resetAfterFork'))->invoke($executor, $log);

            // Stands in for the child's DB reconnect: the first thing opened after the
            // redirect. It must NOT be able to claim fd 1 or fd 2.
            $next = fopen($rival, 'w');

            if (is_resource($err = @fopen('php://stderr', 'a'))) {
                fwrite($err, "STDERR-REACHED-THE-LOG\n");
            }
            if (is_resource($out = @fopen('php://stdout', 'a'))) {
                fwrite($out, "STDOUT-REACHED-THE-LOG\n");
            }

            @fflush($next);
            @pcntl_exec('/bin/true');   // leave without re-entering PHPUnit's shutdown
            posix_kill(getmypid(), SIGKILL);
        }

        pcntl_waitpid($pid, $status);

        $written = (string) @file_get_contents($log);
        $this->assertStringContainsString('STDERR-REACHED-THE-LOG', $written, 'the child had no usable stderr');
        $this->assertStringContainsString('STDOUT-REACHED-THE-LOG', $written, 'the child had no usable stdout');

        $this->assertSame('', (string) @file_get_contents($rival), 'the descriptor opened after the redirect (a real child: its DB socket) captured stdout/stderr writes');
    }

    /**
     * REGRESSION (the field symptom itself): an exception escaping ChildRunner left NO
     * trace anywhere — the attempt row keeps error=NULL (only ChildRunner writes that),
     * so the supervisor synthesized a ProcessDied saying the child "exited with code 0",
     * and the child's own record of the throw went to a closed descriptor. The escape
     * must leave dying words in the attempt log AND a non-zero exit status.
     */
    public function test_an_exception_escaping_the_child_leaves_dying_words_and_a_nonzero_status(): void
    {
        $log = $this->runtime.'/attempt-escape.log';

        $executor = new ForkExecutor($this->app->make(ProcessStampWriter::class), $this->explodingProbe());
        $pid = $executor->fork('01997f00-0000-7000-8000-00000000dead', 1, 'nonce', $log);

        $this->assertGreaterThan(0, $pid, 'could not fork');
        pcntl_waitpid($pid, $status);

        $this->assertTrue(pcntl_wifexited($status), 'the child must exit, not die by signal');
        $this->assertSame(ExitCode::FAILURE, pcntl_wexitstatus($status), 'an escaped exception must not report a clean exit');

        $written = (string) @file_get_contents($log);
        $this->assertStringContainsString('jobwarden prefork child fatal', $written);
        $this->assertStringContainsString('probe exploded before the runner', $written, 'the exception message is lost');
        $this->assertStringContainsString('ForkExecutorTest.php:', $written, 'the throw site is lost');
        $this->assertStringContainsString('#0', $written, 'the stack trace is lost');
    }

    /** A probe that throws where runChild calls it — i.e. before ChildRunner can record anything. */
    private function explodingProbe(): ProcessProbe
    {
        return new class implements ProcessProbe
        {
            public function pidAlive(int $pid): bool
            {
                return true;
            }

            public function startTime(int $pid): ?string
            {
                throw new \RuntimeException('probe exploded before the runner');
            }

            public function ppid(int $pid): ?int
            {
                return null;
            }

            public function signal(int $pid, int $signal): bool
            {
                return false;
            }

            public function matches(int $pid, ?string $expectedStartTime): bool
            {
                return false;
            }

            public function verify(\JobWarden\Process\ProcessStamp $stamp): \JobWarden\Process\VerifyResult
            {
                throw new \RuntimeException('not used');
            }
        };
    }

    /**
     * Tier 2 of the fork-safety contract, in-process (no fork needed — it's pure
     * container arithmetic): every RESOLVED binding on the prefork_forget list is
     * dropped so the next make() rebuilds fresh, the inherited instance is held so no
     * destructor can say goodbye in-band on a shared socket, and a binding the master
     * never resolved is NOT booted just to be forgotten. Unknown keys are ignored.
     */
    public function test_the_forget_list_rebuilds_resolved_services_and_never_boots_unresolved_ones(): void
    {
        $constructions = ['resolved' => 0, 'untouched' => 0];
        $this->app->singleton('wb.resolved', function () use (&$constructions) {
            $constructions['resolved']++;

            return new \stdClass;
        });
        $this->app->singleton('wb.untouched', function () use (&$constructions) {
            $constructions['untouched']++;

            return new \stdClass;
        });
        $inherited = $this->app->make('wb.resolved');

        config(['jobwarden.supervisor.prefork_forget' => ['wb.resolved', 'wb.untouched', 'wb.not-even-bound']]);

        $executor = $this->executor();
        (new ReflectionMethod(ForkExecutor::class, 'forgetFrameworkServices'))->invoke($executor);

        $this->assertSame(0, $constructions['untouched'],
            'a service the master never resolved was booted inside the child just to be forgotten');

        $rebuilt = $this->app->make('wb.resolved');
        $this->assertNotSame($inherited, $rebuilt, 'the listed binding still serves the inherited instance');
        $this->assertSame(2, $constructions['resolved'], 'the rebuild did not go through the singleton binding');

        $held = (new \ReflectionProperty(ForkExecutor::class, 'heldServices'))->getValue($executor);
        $this->assertContains($inherited, $held,
            'the inherited instance is not held — forgetting it can drop it to refcount 0 and fire a destructor mid-child');
    }

    /**
     * REGRESSION (adversarial review): resolved() alias-resolves its argument but
     * forgetInstance() does not — so a forget-list entry written as an alias (the
     * idiomatic class-name key, e.g. CacheManager::class for 'cache') passed the
     * guard, was dutifully "held", and then forgot NOTHING: the master's instance
     * stayed live under the base key and the child kept sharing its sockets, silently.
     * Keys must be alias-normalized before both the guard and the forget.
     */
    public function test_an_aliased_forget_key_still_drops_the_base_binding(): void
    {
        $this->app->singleton('wb.base', fn () => new \stdClass);
        $this->app->alias('wb.base', 'wb.alias');
        $inherited = $this->app->make('wb.base');

        config(['jobwarden.supervisor.prefork_forget' => ['wb.alias']]);
        (new ReflectionMethod(ForkExecutor::class, 'forgetFrameworkServices'))->invoke($this->executor());

        $this->assertNotSame($inherited, $this->app->make('wb.base'),
            'the alias passed the resolved() guard but forgot nothing — the base binding still serves the inherited instance');
    }

    /**
     * REGRESSION (adversarial review): the guard was resolved() alone, which is also
     * true for a plain bind() — for which make() CONSTRUCTS a brand-new instance (a
     * service booted inside every fork, exactly what the contract forbids), holds the
     * pointless fresh object, and forgets nothing. Non-shared bindings must be skipped
     * without ever being instantiated.
     */
    public function test_a_resolved_non_shared_binding_is_skipped_without_being_constructed(): void
    {
        $constructions = 0;
        $this->app->bind('wb.transient', function () use (&$constructions) {
            $constructions++;

            return new \stdClass;
        });
        $this->app->make('wb.transient');
        $this->assertSame(1, $constructions);

        config(['jobwarden.supervisor.prefork_forget' => ['wb.transient']]);
        $executor = $this->executor();
        (new ReflectionMethod(ForkExecutor::class, 'forgetFrameworkServices'))->invoke($executor);

        $this->assertSame(1, $constructions,
            'a non-shared binding was constructed inside the child just to be forgotten');
        $this->assertSame([], (new \ReflectionProperty(ForkExecutor::class, 'heldServices'))->getValue($executor),
            'a freshly built throwaway was held as if it were an inherited instance');
    }

    /**
     * REGRESSION (adversarial review, round 2): facades cache their root under the
     * ACCESSOR string — which may be an alias or a contract name, not the base key —
     * so clearing the facade cache under the normalized key alone left a facade whose
     * accessor is the alias serving the master's instance. Every facade root must be
     * cleared. Also the sqlite-lane guard for the facade half of Tier 2: without a
     * facade in a unit test, deleting the clearing only failed the engine-gated E2E.
     */
    public function test_a_facade_whose_accessor_is_an_alias_stops_serving_the_inherited_root(): void
    {
        $this->app->singleton('wb.base', fn () => new \stdClass);
        $this->app->alias('wb.base', 'wb.alias-name');
        $inherited = AliasAccessorFacade::getFacadeRoot();   // populates the static cache under the ALIAS
        $this->assertSame($inherited, $this->app->make('wb.base'));

        config(['jobwarden.supervisor.prefork_forget' => ['wb.base']]);
        (new ReflectionMethod(ForkExecutor::class, 'forgetFrameworkServices'))->invoke($this->executor());

        $this->assertNotSame($inherited, AliasAccessorFacade::getFacadeRoot(),
            'the facade (accessor = alias) still serves the master\'s inherited root — the facade cache was cleared under the base key only');
        $this->assertSame(AliasAccessorFacade::getFacadeRoot(), $this->app->make('wb.base'),
            'facade and container disagree about the current instance');
    }

    /**
     * REGRESSION (adversarial review, round 2): $app->instance() — the common way to
     * register a pre-built SDK client — passes resolved()+isShared() but has no binding
     * to rebuild from, so forgetting it made the child's first use autowire a different
     * object or throw. Such keys must be left alone (they are hook territory).
     */
    public function test_an_instance_registered_key_is_left_alone_because_it_cannot_be_rebuilt(): void
    {
        $prebuilt = new \stdClass;
        $this->app->instance('wb.prebuilt', $prebuilt);

        config(['jobwarden.supervisor.prefork_forget' => ['wb.prebuilt']]);
        $executor = $this->executor();
        (new ReflectionMethod(ForkExecutor::class, 'forgetFrameworkServices'))->invoke($executor);

        $this->assertSame($prebuilt, $this->app->make('wb.prebuilt'),
            'an instance()-registered service was forgotten with nothing to rebuild it from');
        $this->assertSame([], (new \ReflectionProperty(ForkExecutor::class, 'heldServices'))->getValue($executor));
    }

    /**
     * REGRESSION (adversarial review, round 2): a config published before 1.17 replaces
     * the whole 'supervisor' block (shallow merge), so the key is simply absent — and
     * an in-code fallback of [] silently disabled Tier 2 for exactly those apps. The
     * fallback must be the shipped list.
     */
    public function test_a_missing_forget_key_falls_back_to_the_shipped_defaults(): void
    {
        config(['jobwarden.supervisor.prefork_forget' => null]);
        $inheritedLog = $this->app->make('log');

        (new ReflectionMethod(ForkExecutor::class, 'forgetFrameworkServices'))->invoke($this->executor());

        $this->assertNotSame($inheritedLog, $this->app->make('log'),
            'with the key absent nothing was forgotten — the fallback is not the shipped default list');
    }

    /**
     * REGRESSION: hardExit terminates via pcntl_exec, so the exec'd image's status IS
     * the child's status. Exec'ing /bin/true for every outcome reported a clean 0 for
     * a child that died mid-flight, which the supervisor renders to an operator as
     * "child exited with code 0 after Ns without reporting".
     */
    #[DataProvider('exitCodes')]
    public function test_hard_exit_carries_the_real_exit_code_out_of_the_fork(int $code): void
    {
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'could not fork');

        if ($pid === 0) {
            (new ReflectionMethod(ForkExecutor::class, 'hardExit'))->invoke($this->executor(), $code);
        }

        pcntl_waitpid($pid, $status);

        $this->assertTrue(pcntl_wifexited($status), 'the child must exit, not die by signal');
        $this->assertSame($code, pcntl_wexitstatus($status));
    }

    /** @return array<string, array{int}> */
    public static function exitCodes(): array
    {
        return [
            'success' => [ExitCode::SUCCESS],
            'handler threw' => [ExitCode::FAILURE],
            'graceful stop' => [ExitCode::GRACEFUL_STOP],
            'stale token' => [ExitCode::STALE_TOKEN],
        ];
    }
}
