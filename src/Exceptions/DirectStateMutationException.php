<?php

declare(strict_types=1);

namespace JobWarden\Exceptions;

use RuntimeException;

/**
 * Something tried to change a `state` column through a model save/update instead
 * of the StateMachine. Blocked to preserve auditability (spec §11).
 */
final class DirectStateMutationException extends RuntimeException
{
    public function __construct(string $model)
    {
        parent::__construct(
            "Direct mutation of {$model}::state is forbidden — route the change through the StateMachine."
        );
    }
}
