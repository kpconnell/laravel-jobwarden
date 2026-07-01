<?php

declare(strict_types=1);

namespace JobWarden\Tests;

use JobWarden\JobWardenServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** The engine under test for this run: sqlite | pgsql | mysql. */
    protected function engine(): string
    {
        return getenv('JOBWARDEN_TEST_DB') ?: 'sqlite';
    }

    protected function getPackageProviders($app): array
    {
        return [
            \Livewire\LivewireServiceProvider::class,
            JobWardenServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        // JobWarden runs on its own dedicated connection (spec §2). Wire it from
        // line one; the whole engine resolves DB handles via this name.
        $config->set('jobwarden.connection', 'jobwarden');

        $config->set('database.connections.jobwarden', $this->jobwardenConnectionConfig());

        // A file-less default log channel: Log:: calls fire MessageLogged (so the
        // job-log capture works) but discard output, never touching the disk.
        $config->set('logging.default', 'jobwarden_test');
        $config->set('logging.channels.jobwarden_test', [
            'driver' => 'monolog',
            'handler' => \Monolog\Handler\NullHandler::class,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function jobwardenConnectionConfig(): array
    {
        return match ($this->engine()) {
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => getenv('JOBWARDEN_DB_HOST') ?: '127.0.0.1',
                'port' => getenv('JOBWARDEN_DB_PORT') ?: '5432',
                'database' => getenv('JOBWARDEN_DB_NAME') ?: 'jobwarden',
                'username' => getenv('JOBWARDEN_DB_USER') ?: 'jobwarden',
                'password' => getenv('JOBWARDEN_DB_PASSWORD') ?: 'secret',
                'charset' => 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ],
            'mysql' => [
                'driver' => 'mysql',
                'host' => getenv('JOBWARDEN_DB_HOST') ?: '127.0.0.1',
                'port' => getenv('JOBWARDEN_DB_PORT') ?: '3306',
                'database' => getenv('JOBWARDEN_DB_NAME') ?: 'jobwarden',
                'username' => getenv('JOBWARDEN_DB_USER') ?: 'jobwarden',
                'password' => getenv('JOBWARDEN_DB_PASSWORD') ?: 'secret',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        };
    }
}
