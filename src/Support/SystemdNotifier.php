<?php

declare(strict_types=1);

namespace JobWarden\Support;

/**
 * Minimal sd_notify(3) over $NOTIFY_SOCKET — PHP has no native binding, so the
 * one Linux-ops primitive we need (a systemd Type=notify watchdog for the local
 * reaper, spec §6.5) is hand-rolled here. A no-op when not run under systemd.
 */
final class SystemdNotifier
{
    private bool $enabled;

    private string $socketPath;

    public function __construct(?string $notifySocket = null)
    {
        $socket = $notifySocket ?? (getenv('NOTIFY_SOCKET') ?: '');
        $this->enabled = $socket !== '';
        // Abstract-namespace sockets start with '@' → a leading NUL byte.
        $this->socketPath = str_starts_with($socket, '@') ? "\0".substr($socket, 1) : $socket;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function ready(): void
    {
        $this->send("READY=1");
    }

    public function watchdog(): void
    {
        $this->send("WATCHDOG=1");
    }

    public function stopping(): void
    {
        $this->send("STOPPING=1");
    }

    public function status(string $text): void
    {
        $this->send('STATUS='.$text);
    }

    private function send(string $message): void
    {
        if (! $this->enabled || ! function_exists('socket_create')) {
            return;
        }

        $socket = @socket_create(AF_UNIX, SOCK_DGRAM, 0);
        if ($socket === false) {
            return;
        }

        @socket_sendto($socket, $message, strlen($message), 0, $this->socketPath, 0);
        socket_close($socket);
    }
}
