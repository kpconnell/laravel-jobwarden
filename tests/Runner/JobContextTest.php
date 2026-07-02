<?php

declare(strict_types=1);

namespace JobWarden\Tests\Runner;

use JobWarden\Runner\JobContext;
use PHPUnit\Framework\TestCase;

/**
 * The cooperative stop flag: JobContext::stopRequested() reflects the runner's
 * SIGTERM flag live (the closure re-reads it), and is safely false when no
 * signal source is wired (direct handler invocation in tests).
 */
final class JobContextTest extends TestCase
{
    public function test_stop_requested_reflects_the_signal_flag_live(): void
    {
        $flag = false;
        $context = new JobContext('j', 'a', 1, function () use (&$flag): bool {
            return $flag;
        });

        $this->assertFalse($context->stopRequested());

        $flag = true; // the SIGTERM handler flips the runner's flag mid-run

        $this->assertTrue($context->stopRequested());
    }

    public function test_stop_requested_is_false_without_a_signal_source(): void
    {
        $this->assertFalse((new JobContext('j', 'a', 1))->stopRequested());
    }
}
