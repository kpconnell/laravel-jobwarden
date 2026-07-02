<?php

declare(strict_types=1);

namespace JobWarden\StateMachine\Guards;

use JobWarden\StateMachine\Contracts\Guard;
use JobWarden\StateMachine\TransitionContext;
use Illuminate\Database\Eloquent\Model;
use JobWarden\Support\SqlTime;

/**
 * The job's available_at has been reached (or is unset). Gates admission out of
 * pending/retrying.
 */
final class AvailableAtReachedGuard implements Guard
{
    public function passes(Model $entity, TransitionContext $context): bool
    {
        if ($entity->getAttribute('available_at') === null) {
            return true;
        }

        // Compare against the DB clock in SQL, not the app clock: available_at is stored
        // in the DB's timezone frame, and reading it back through Eloquent re-parses it in
        // the app timezone (a wrong absolute instant under any TZ mismatch). Evaluating
        // server-side keeps this consistent with the claim + admit checks.
        $conn = $entity->getConnection();
        $now = SqlTime::nowExpr($conn);
        $row = $conn->selectOne(
            "SELECT CASE WHEN available_at IS NULL OR available_at <= {$now} THEN 1 ELSE 0 END AS ok"
            ." FROM {$entity->getTable()} WHERE {$entity->getKeyName()} = ?",
            [$entity->getKey()]
        );

        return (int) ($row->ok ?? 0) === 1;
    }

    public function reason(): string
    {
        return 'available_at has not been reached';
    }
}
