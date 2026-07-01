<?php

declare(strict_types=1);

namespace JobWarden\Supervisor;

/**
 * In-memory map of the supervisor's live children, keyed by attempt id.
 */
final class ChildRegistry
{
    /** @var array<string, ChildHandle> */
    private array $children = [];

    public function add(ChildHandle $handle): void
    {
        $this->children[$handle->attemptId] = $handle;
    }

    public function forget(string $attemptId): void
    {
        unset($this->children[$attemptId]);
    }

    public function get(string $attemptId): ?ChildHandle
    {
        return $this->children[$attemptId] ?? null;
    }

    /** @return ChildHandle[] */
    public function all(): array
    {
        return array_values($this->children);
    }

    public function count(): int
    {
        return count($this->children);
    }

    public function isEmpty(): bool
    {
        return $this->children === [];
    }
}
