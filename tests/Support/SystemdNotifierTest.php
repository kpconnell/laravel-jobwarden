<?php

declare(strict_types=1);

namespace JobWarden\Tests\Support;

use JobWarden\Support\SystemdNotifier;
use PHPUnit\Framework\TestCase;

final class SystemdNotifierTest extends TestCase
{
    public function test_no_op_without_a_notify_socket(): void
    {
        $notifier = new SystemdNotifier('');
        $this->assertFalse($notifier->enabled());
        $notifier->ready();      // must not throw
        $notifier->watchdog();
        $this->assertTrue(true);
    }

    public function test_sends_ready_and_watchdog_to_the_notify_socket(): void
    {
        if (! function_exists('socket_create')) {
            $this->markTestSkipped('ext-sockets not available');
        }

        $path = sys_get_temp_dir().'/jobwarden-notify-'.bin2hex(random_bytes(4)).'.sock';
        @unlink($path);

        $server = socket_create(AF_UNIX, SOCK_DGRAM, 0);
        $this->assertTrue(socket_bind($server, $path));

        $notifier = new SystemdNotifier($path);
        $this->assertTrue($notifier->enabled());

        $notifier->ready();
        $notifier->watchdog();

        $received = [];
        for ($i = 0; $i < 2; $i++) {
            $buf = '';
            $from = '';
            if (@socket_recvfrom($server, $buf, 128, 0, $from) !== false && $buf !== '') {
                $received[] = $buf;
            }
        }

        socket_close($server);
        @unlink($path);

        $this->assertContains('READY=1', $received);
        $this->assertContains('WATCHDOG=1', $received);
    }
}
