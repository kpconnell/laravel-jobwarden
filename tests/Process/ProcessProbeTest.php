<?php

declare(strict_types=1);

namespace JobWarden\Tests\Process;

use JobWarden\Process\Fake\FakeProbe;
use JobWarden\Process\LinuxHostIdentity;
use JobWarden\Process\LinuxProcProbe;
use JobWarden\Process\Pidfile;
use JobWarden\Process\ProcessStamp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Verifies the Linux process layer against REAL processes (runs in the Docker
 * Linux container), plus the parser/host-identity/pidfile/fake units.
 */
final class ProcessProbeTest extends TestCase
{
    private string $runtime;

    protected function setUp(): void
    {
        $this->runtime = sys_get_temp_dir().'/jobwarden-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @array_map('unlink', glob($this->runtime.'/pids/*') ?: []);
    }

    private function linuxOnly(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('LinuxProcProbe requires /proc (run in the Docker Linux stack).');
        }
    }

    public function test_probe_verifies_a_real_child_then_detects_its_death(): void
    {
        $this->linuxOnly();

        $pidfile = new Pidfile($this->runtime);
        $probe = new LinuxProcProbe($pidfile);

        $proc = new Process(['sleep', '30']);
        $proc->start();
        $pid = $proc->getPid();
        $this->assertNotNull($pid);

        try {
            // Alive, with a reuse-proof start-time and a parent.
            $this->assertTrue($probe->pidAlive($pid));
            $startTime = $probe->startTime($pid);
            $this->assertNotNull($startTime);
            $this->assertMatchesRegularExpression('/^\d+$/', $startTime);
            $this->assertNotNull($probe->ppid($pid));

            $this->assertTrue($probe->matches($pid, $startTime));
            $this->assertFalse($probe->matches($pid, '999999999'), 'wrong start-time must not match');

            // Full stamp verification: write the nonce pidfile, then verify.
            $attemptId = 'attempt-xyz';
            $nonce = 'nonce-'.bin2hex(random_bytes(4));
            $pidfile->write($attemptId, ['pid' => $pid, 'start_time' => $startTime, 'nonce' => $nonce, 'token' => 1]);

            $good = new ProcessStamp($attemptId, 'host', null, null, $pid, $startTime, $nonce);
            $this->assertTrue($probe->verify($good)->verified());

            // A reused pid: same pid, but stamped start-time / nonce don't match.
            $staleStart = new ProcessStamp($attemptId, 'host', null, null, $pid, '111', $nonce);
            $this->assertFalse($probe->verify($staleStart)->verified());

            $wrongNonce = new ProcessStamp($attemptId, 'host', null, null, $pid, $startTime, 'other-nonce');
            $this->assertFalse($probe->verify($wrongNonce)->verified());
        } finally {
            $proc->stop(0);
        }

        // After death: not alive, verify reports dead.
        $proc->wait();
        $this->assertFalse($probe->pidAlive($pid));
        $dead = new ProcessStamp('attempt-xyz', 'host', null, null, $pid, '123', 'n');
        $this->assertFalse($probe->verify($dead)->alive);
    }

    public function test_stat_field_parser_survives_an_ugly_comm(): void
    {
        // comm contains spaces AND parens; field 22 (starttime) must still parse.
        // pid (comm) state ppid pgrp ... up to field 22.
        $fields3to22 = 'S 1 1 0 0 -1 4194560 100 0 0 0 1 2 0 0 20 0 1 0 8675309';
        $stat = '4242 (weird ) (proc) name) '.$fields3to22.' 0 0 0';

        $this->assertSame('8675309', LinuxProcProbe::parseField($stat, 22)); // starttime
        $this->assertSame('1', LinuxProcProbe::parseField($stat, 4));        // ppid
    }

    public function test_host_identity_is_deterministic_and_distinguishes_machines(): void
    {
        $a1 = new LinuxHostIdentity('machine-a', 'boot-1');
        $a2 = new LinuxHostIdentity('machine-a', 'boot-1');
        $b = new LinuxHostIdentity('machine-b', 'boot-1');
        $aRebooted = new LinuxHostIdentity('machine-a', 'boot-2');

        $this->assertSame($a1->hostId(), $a2->hostId(), 'stable for the same machine+boot');
        $this->assertNotSame($a1->hostId(), $b->hostId(), 'different machines differ');
        $this->assertNotSame($a1->hostId(), $aRebooted->hostId(), 'a reboot changes the id');
    }

    public function test_real_machine_id_is_readable_in_the_container(): void
    {
        $this->linuxOnly();

        $hostId = (new LinuxHostIdentity)->hostId();
        $this->assertNotEmpty($hostId);
        $this->assertSame(64, strlen($hostId)); // sha256 hex
    }

    public function test_pidfile_round_trips(): void
    {
        $pidfile = new Pidfile($this->runtime);
        $pidfile->write('a-1', ['pid' => 123, 'start_time' => '456', 'nonce' => 'n1', 'token' => 2]);

        $read = $pidfile->read('a-1');
        $this->assertSame(123, $read['pid']);
        $this->assertSame('n1', $read['nonce']);
        $this->assertSame('a-1', $read['attempt_id']);

        $pidfile->delete('a-1');
        $this->assertNull($pidfile->read('a-1'));
    }

    public function test_fake_probe_models_liveness_and_signal_escalation(): void
    {
        $fake = new FakeProbe;
        $fake->spawn(pid: 500, startTime: '900', ppid: 1, attemptId: 'a', nonce: 'n');

        $stamp = new ProcessStamp('a', 'host', null, null, 500, '900', 'n');
        $this->assertTrue($fake->verify($stamp)->verified());

        // SIGTERM is recorded but the stubborn child stays alive.
        $fake->signal(500, FakeProbe::SIGTERM);
        $this->assertTrue($fake->pidAlive(500));

        // SIGKILL kills it; a re-probe confirms death.
        $fake->signal(500, FakeProbe::SIGKILL);
        $this->assertFalse($fake->pidAlive(500));
        $this->assertFalse($fake->verify($stamp)->alive);
        $this->assertSame([FakeProbe::SIGTERM, FakeProbe::SIGKILL], $fake->signalsSentTo(500));
    }
}
