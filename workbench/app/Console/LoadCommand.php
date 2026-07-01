<?php

declare(strict_types=1);

namespace Workbench\App\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\Uuid;
use Workbench\App\Jobs\CpuJob;

/**
 * Bulk-insert N queued CPU-burning jobs for load testing — chunked raw inserts so
 * we can seed tens of thousands of jobs in a second or two (JobWarden::dispatch
 * one at a time is far too slow at this scale). `--cpu` sets the hash iterations
 * each job performs (the per-job CPU cost).
 */
final class LoadCommand extends Command
{
    protected $signature = 'jobwarden:demo:load {count=10000} {--cpu=200000 : hash iterations per job} {--max-attempts=1 : retry budget} {--lane=default}';

    protected $description = 'Bulk-insert N queued CPU jobs for load testing.';

    public function handle(): int
    {
        $n = (int) $this->argument('count');
        $lane = (string) $this->option('lane');
        $conn = DB::connection(config('jobwarden.connection'));
        $table = ((string) config('jobwarden.table_prefix')).'jobs';
        $now = Carbon::now();
        $params = json_encode(['cpu' => (int) $this->option('cpu')]);

        $bar = $this->output->createProgressBar($n);
        $chunk = [];
        $inserted = 0;
        for ($i = 0; $i < $n; $i++) {
            $chunk[] = [
                'id' => (string) Uuid::v7(),
                'job_class' => CpuJob::class,
                'name' => 'load',
                'lane' => $lane,
                'params' => $params,
                'idempotent' => true,
                'priority' => 0,
                'state' => 'queued',
                'available_at' => $now,
                'max_attempts' => (int) $this->option('max-attempts'),
                'attempt_count' => 0,
                'created_at' => $now,
                'queued_at' => $now,
                'updated_at' => $now,
            ];
            if (count($chunk) >= 1000) {
                $conn->table($table)->insert($chunk);
                $inserted += count($chunk);
                $bar->advance(count($chunk));
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $conn->table($table)->insert($chunk);
            $inserted += count($chunk);
            $bar->advance(count($chunk));
        }
        $bar->finish();
        $this->newLine();
        $this->info("inserted {$inserted} queued jobs (lane={$lane})");

        return self::SUCCESS;
    }
}
