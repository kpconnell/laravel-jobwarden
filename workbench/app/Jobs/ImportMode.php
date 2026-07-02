<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

/** Workbench fixture: a string-backed enum bound from params by HandlerFactory. */
enum ImportMode: string
{
    case Full = 'full';
    case Incremental = 'incremental';
}
