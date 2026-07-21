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
