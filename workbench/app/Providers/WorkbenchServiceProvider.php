<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Configures the dedicated `jobwarden` connection (and the supervisor's child
 * spawn command) from environment for the runnable workbench app — so
 * `vendor/bin/testbench jobwarden:work` and the children it spawns boot against
 * the same Postgres in the Docker stack.
 */
final class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $root = dirname(__DIR__, 3); // package root (/app in the container)

        // Demo convenience: open the operator API gate in the workbench app so the
        // dashboard/API can be browsed locally. Real apps wire JobWarden::auth().
        \JobWarden\JobWarden::auth(fn ($request) => true);

        $driver = env('JOBWARDEN_DB_DRIVER', 'pgsql');

        $connection = $driver === 'mysql'
            ? [
                'driver' => 'mysql',
                'host' => env('JOBWARDEN_DB_HOST', '127.0.0.1'),
                'port' => env('JOBWARDEN_DB_PORT', '3306'),
                'database' => env('JOBWARDEN_DB_NAME', 'jobwarden'),
                'username' => env('JOBWARDEN_DB_USER', 'jobwarden'),
                'password' => env('JOBWARDEN_DB_PASSWORD', 'secret'),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                // FOUND_ROWS + READ COMMITTED are also force-set by the package
                // provider; declared here too so it's explicit for the demo stack.
            ]
            : [
                'driver' => 'pgsql',
                'host' => env('JOBWARDEN_DB_HOST', '127.0.0.1'),
                'port' => env('JOBWARDEN_DB_PORT', '5432'),
                'database' => env('JOBWARDEN_DB_NAME', 'jobwarden'),
                'username' => env('JOBWARDEN_DB_USER', 'jobwarden'),
                'password' => env('JOBWARDEN_DB_PASSWORD', 'secret'),
                'charset' => 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ];

        config([
            'jobwarden.connection' => 'jobwarden',

            'database.connections.jobwarden' => $connection,

            // The supervisor spawns children through the workbench-aware entry
            // point so they load this provider (and the connection above).
            'jobwarden.supervisor.run_command' => [PHP_BINARY, $root.'/vendor/bin/testbench', 'jobwarden:run'],
            'jobwarden.supervisor.run_cwd' => $root,
            'jobwarden.runtime_path' => $root.'/workbench/storage/jobwarden',

            // Daemons (supervisor/reapers) log their lifecycle to STDOUT — the
            // conventional home for container/cloud log capture. The child
            // (jobwarden:run) overrides this to a NullHandler so its structured
            // logs go only to job_logs (see RunCommand).
            'logging.default' => 'jobwarden_stdout',
            'logging.channels.jobwarden_stdout' => [
                'driver' => 'monolog',
                'handler' => \Monolog\Handler\StreamHandler::class,
                'with' => ['stream' => 'php://stdout'],
                'level' => 'debug',
            ],
        ]);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Workbench\App\Console\DemoDispatchCommand::class,
                \Workbench\App\Console\DemoBatchCommand::class,
                \Workbench\App\Console\DemoScheduleCommand::class,
                \Workbench\App\Console\EchoCommand::class,
                \Workbench\App\Console\KickoffCommand::class,
                \Workbench\App\Console\LoadCommand::class,
            ]);
        }
    }
}
