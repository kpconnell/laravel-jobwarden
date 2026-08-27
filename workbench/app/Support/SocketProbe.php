<?php

declare(strict_types=1);

namespace Workbench\App\Support;

/**
 * A stand-in for a connection-holding app singleton that lets tests reason at the
 * KERNEL level, not the object level: it opens a real TCP connection at construction
 * (to the test DB's port — the one endpoint the E2E gate guarantees reachable) and
 * exposes the connection's local ephemeral port. Two processes reporting the same
 * local ip:port are on the SAME kernel socket; a fresh connection cannot reuse it
 * while the original is open. So "child's localName() differs from the master's" is
 * proof of a different socket — which is the actual fork-safety property, of which
 * "different object instance" is only a proxy.
 */
final class SocketProbe
{
    /** @var resource */
    private $stream;

    public readonly int $constructedInPid;

    public function __construct()
    {
        $cfg = (array) config('database.connections.'.config('jobwarden.connection'));
        $endpoint = 'tcp://'.($cfg['host'] ?? '127.0.0.1').':'.($cfg['port'] ?? 3306);

        $stream = @stream_socket_client($endpoint, $errno, $errstr, 5.0);
        if ($stream === false) {
            throw new \RuntimeException("SocketProbe could not connect to {$endpoint}: {$errstr}");
        }
        $this->stream = $stream;
        $this->constructedInPid = (int) getmypid();
    }

    /** The kernel identity of this connection: its local "ip:port". */
    public function localName(): string
    {
        return (string) stream_socket_get_name($this->stream, false);
    }
}
