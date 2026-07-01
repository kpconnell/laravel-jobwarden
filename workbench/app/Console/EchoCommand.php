<?php

declare(strict_types=1);

namespace Workbench\App\Console;

use Illuminate\Console\Command;

/**
 * A trivial command for exercising scheduled-command execution: prints a message
 * and returns a controllable exit code.
 */
final class EchoCommand extends Command
{
    protected $signature = 'demo:exit {--code=0 : exit code} {--message=hello-from-command : line to print}';

    protected $description = 'Print a message and exit with the given code (for testing scheduled commands).';

    public function handle(): int
    {
        $this->line((string) $this->option('message'));

        return (int) $this->option('code');
    }
}
