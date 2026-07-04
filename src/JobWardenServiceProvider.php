<?php

declare(strict_types=1);

namespace JobWarden;

use JobWarden\Claim\ClaimDriverFactory;
use JobWarden\Claim\Contracts\ClaimDriver;
use JobWarden\Process\Contracts\HostIdentity;
use JobWarden\Process\Contracts\ProcessProbe;
use JobWarden\Process\Contracts\ProcessTitle;
use JobWarden\Process\Fake\FakeHostIdentity;
use JobWarden\Process\LinuxHostIdentity;
use JobWarden\Process\NativeProcessTitle;
use JobWarden\Process\Pidfile;
use JobWarden\Process\ProbeFactory;
use JobWarden\StateMachine\StateMachine;
use JobWarden\Stamp\ProcessStampWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use PDO;

class JobWardenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/jobwarden.php', 'jobwarden');

        // The single authoritative state mutator (spec §11).
        $this->app->singleton(StateMachine::class);

        // Claim driver, selected per-engine from config (spec §5.1/§5.2).
        $this->app->singleton(ClaimDriverFactory::class);
        $this->app->bind(ClaimDriver::class, fn ($app) => $app->make(ClaimDriverFactory::class)->make());

        // Process layer (spec §5.3). One real probe (Linux); FakeProbe is the
        // test seam. Host identity is boot-stable (machine-id + boot_id).
        $this->app->singleton(Pidfile::class, fn () => new Pidfile((string) config('jobwarden.runtime_path')));
        $this->app->singleton(ProcessProbe::class, fn () => (new ProbeFactory)->make());
        $this->app->singleton(HostIdentity::class, fn () => config('jobwarden.process.probe') === 'fake' || PHP_OS_FAMILY !== 'Linux'
            ? new FakeHostIdentity
            : new LinuxHostIdentity);
        $this->app->singleton(ProcessTitle::class, fn () => new NativeProcessTitle);
        $this->app->singleton(ProcessStampWriter::class);

        // Logging: LogIndex over a pluggable body sink (spec §4.5). The capture
        // bridge + writer are singletons so the Log listener and seq counters are
        // shared within a process.
        $this->app->singleton(\JobWarden\Logging\Contracts\LogBodySink::class, function (): \JobWarden\Logging\Contracts\LogBodySink {
            return match ((string) config('jobwarden.logs.sink', 'database')) {
                default => new \JobWarden\Logging\Sinks\DatabaseSink,
            };
        });
        $this->app->singleton(\JobWarden\Logging\JobLogger::class);
        $this->app->singleton(\JobWarden\Logging\JobLogCapture::class);

        // Entry-point service for putting work into JobWarden (spec §0).
        $this->app->singleton(JobWarden::class);
        $this->app->alias(JobWarden::class, 'jobwarden');
    }

    public function boot(): void
    {
        $this->alignMysqlRowSemantics();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/jobwarden.php' => config_path('jobwarden.php'),
            ], 'jobwarden-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'jobwarden-migrations');

            $this->commands([
                \JobWarden\Console\WorkCommand::class,
                \JobWarden\Console\ScheduledWorkerCommand::class,
                \JobWarden\Console\RunCommand::class,
                \JobWarden\Console\StatusCommand::class,
                \JobWarden\Console\LogsCommand::class,
                \JobWarden\Console\GlobalReaperCommand::class,
                \JobWarden\Console\LocalReaperCommand::class,
                \JobWarden\Console\CancelCommand::class,
                \JobWarden\Console\StopCommand::class,
                \JobWarden\Console\RetryCommand::class,
                \JobWarden\Console\RestartCommand::class,
                \JobWarden\Console\BatchCommand::class,
                \JobWarden\Console\InstallCommand::class,
                \JobWarden\Console\WorkersCommand::class,
                \JobWarden\Console\PruneCommand::class,
                \JobWarden\Console\RetagCommand::class,
                \JobWarden\Console\ScheduleCommand::class,
            ]);
        }

        // Batch coordination reacts to member job transitions (spec §8).
        $this->app['events']->listen(
            \JobWarden\Events\JobStateChanged::class,
            [\JobWarden\Batch\BatchCoordinator::class, 'onJobStateChanged'],
        );

        // Migrations declare their own connection via config('jobwarden.connection').
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerOperatorApi();
        $this->registerDashboard();
    }

    /** Mount the Livewire operator dashboard (server-rendered, DB-polled). */
    private function registerDashboard(): void
    {
        if (! config('jobwarden.dashboard.enabled', true) || ! class_exists(\Livewire\Livewire::class)) {
            return;
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'jobwarden');

        \Livewire\Livewire::component('jobwarden.overview', \JobWarden\Http\Livewire\Overview::class);
        \Livewire\Livewire::component('jobwarden.jobs', \JobWarden\Http\Livewire\Jobs::class);
        \Livewire\Livewire::component('jobwarden.job-show', \JobWarden\Http\Livewire\JobShow::class);
        \Livewire\Livewire::component('jobwarden.batches', \JobWarden\Http\Livewire\Batches::class);
        \Livewire\Livewire::component('jobwarden.schedules', \JobWarden\Http\Livewire\Schedules::class);
        \Livewire\Livewire::component('jobwarden.workers', \JobWarden\Http\Livewire\Workers::class);

        Route::group([
            'prefix' => config('jobwarden.dashboard.prefix', 'jobwarden'),
            'middleware' => array_merge(
                (array) config('jobwarden.dashboard.middleware', ['web']),
                [\JobWarden\Http\Middleware\Authorize::class],
            ),
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/dashboard.php');
        });
    }

    /** Mount the operator API (read + actions) behind the Authorize gate. */
    private function registerOperatorApi(): void
    {
        if (! config('jobwarden.api.enabled', true)) {
            return;
        }

        Route::group([
            'prefix' => config('jobwarden.api.prefix', 'jobwarden/api'),
            'middleware' => array_merge(
                [\JobWarden\Http\Middleware\ForceJson::class],
                (array) config('jobwarden.api.middleware', ['api']),
                [\JobWarden\Http\Middleware\Authorize::class],
            ),
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/operator.php');
        });
    }

    /**
     * Align the dedicated MySQL/MariaDB connection with the semantics JobWarden's
     * correctness depends on (RDS MariaDB is the first production target):
     *
     *  1. MYSQL_ATTR_FOUND_ROWS — the whole concurrency model is "guarded UPDATE …
     *     WHERE …; affected==1 ⇒ I own it". MySQL otherwise reports CHANGED rows,
     *     not MATCHED rows, so a guarded UPDATE re-writing identical values (e.g. a
     *     lease refresh in the same clock-second) reports 0 and looks like a lost
     *     race. FOUND_ROWS makes affected-rows mean MATCHED rows, exactly like PG.
     *
     *  2. READ COMMITTED — MySQL/MariaDB default to REPEATABLE READ, whose gap/
     *     next-key locks over-lock the `FOR UPDATE SKIP LOCKED` claim scan and stale
     *     the reaper's snapshot. READ COMMITTED is the correct isolation for this
     *     guarded-UPDATE + CURRENT_TIMESTAMP workload.
     *
     * Both are set only when the operator hasn't chosen otherwise.
     */
    private function alignMysqlRowSemantics(): void
    {
        $name = config('jobwarden.connection');
        $config = config("database.connections.{$name}");

        if (! is_array($config) || ($config['driver'] ?? null) !== 'mysql') {
            return;
        }

        $changed = false;

        $options = $config['options'] ?? [];
        if (! array_key_exists(PDO::MYSQL_ATTR_FOUND_ROWS, $options)) {
            $options[PDO::MYSQL_ATTR_FOUND_ROWS] = true;
            config(["database.connections.{$name}.options" => $options]);
            $changed = true;
        }

        if (! array_key_exists('isolation_level', $config)) {
            config(["database.connections.{$name}.isolation_level" => 'READ COMMITTED']);
            $changed = true;
        }

        if ($changed) {
            DB::purge($name); // drop any half-open handle so it reconnects with the new settings
        }
    }
}
