<?php

declare(strict_types=1);

namespace JobWarden\Console;

use JobWarden\Search\TagWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-run param promotion (jobwarden.search.promoted_params) over EXISTING jobs
 * so a config change makes history searchable, not just future dispatches.
 * Insert-or-ignore: params are immutable after creation, so an existing row
 * (explicit or previously promoted) is already correct and is left alone —
 * which also makes the command idempotent and safe to re-run anytime.
 */
final class RetagCommand extends Command
{
    protected $signature = 'jobwarden:retag {--chunk=1000 : jobs per batch}';

    protected $description = 'Promote configured params to searchable tags on existing jobs.';

    public function handle(): int
    {
        $promoted = (array) config('jobwarden.search.promoted_params', []);
        if ($promoted === []) {
            $this->warn('jobwarden.search.promoted_params is empty — nothing to promote.');

            return self::SUCCESS;
        }

        $conn = DB::connection(config('jobwarden.connection'));
        $prefix = (string) config('jobwarden.table_prefix', 'jobwarden_');

        $scanned = 0;
        $inserted = 0;
        $conn->table($prefix.'jobs')
            ->select('id', 'params')
            ->whereNotNull('params')
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($jobs) use ($conn, $prefix, &$scanned, &$inserted): void {
                $rows = [];
                foreach ($jobs as $job) {
                    $scanned++;
                    $params = json_decode((string) $job->params, true);
                    foreach (TagWriter::promoted(is_array($params) ? $params : []) as $name => $value) {
                        $rows[] = ['job_id' => $job->id, 'name' => $name, 'value' => $value];
                    }
                }

                if ($rows !== []) {
                    $inserted += $conn->table($prefix.'job_tags')->insertOrIgnore($rows);
                }
            });

        $this->info("retagged: scanned {$scanned} jobs, added {$inserted} tags (".implode(', ', $promoted).')');

        return self::SUCCESS;
    }
}
