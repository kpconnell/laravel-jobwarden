<?php

declare(strict_types=1);

namespace JobWarden\Console;

use Illuminate\Console\Command;

/**
 * Publish config + migrations (spec §10.2). Run once when adding JobWarden to an
 * app, then configure config/jobwarden.php (the dedicated connection) and migrate.
 */
final class InstallCommand extends Command
{
    protected $signature = 'jobwarden:install {--migrate : run migrations against the jobwarden connection}';

    protected $description = 'Publish JobWarden config + migrations.';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'jobwarden-config', '--force' => false]);
        $this->call('vendor:publish', ['--tag' => 'jobwarden-migrations', '--force' => false]);

        $this->info('Published config/jobwarden.php and migrations.');

        if ($this->option('migrate')) {
            $this->call('migrate', ['--database' => config('jobwarden.connection'), '--force' => true]);
        } else {
            $this->line('Next: set the dedicated connection in config/jobwarden.php, then run');
            $this->line('  php artisan migrate --database='.config('jobwarden.connection', 'jobwarden'));
        }

        return self::SUCCESS;
    }
}
