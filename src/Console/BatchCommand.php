<?php

declare(strict_types=1);

namespace JobWarden\Console;

use JobWarden\Models\Batch;
use JobWarden\Models\Job;
use Illuminate\Console\Command;

/**
 * Inspect a batch (spec §10.2): its state, failure policy, progress counters, and
 * its member jobs.
 */
final class BatchCommand extends Command
{
    protected $signature = 'jobwarden:batch {batch : id or prefix} {--members : list member jobs}';

    protected $description = 'Show a batch, its progress, and (optionally) its members.';

    public function handle(): int
    {
        $batch = Batch::where('id', $this->argument('batch'))->first()
            ?? Batch::where('id', 'like', $this->argument('batch').'%')->orderByDesc('created_at')->first();

        if ($batch === null) {
            $this->error('batch not found');

            return self::FAILURE;
        }

        $this->table(['field', 'value'], [
            ['id', $batch->id],
            ['name', $batch->name],
            ['state', $batch->state->value],
            ['failure_policy', $batch->failure_policy.($batch->failure_threshold !== null ? " (threshold={$batch->failure_threshold})" : '')],
            ['total', $batch->total_jobs],
            ['pending', $batch->pending_count],
            ['running', $batch->running_count],
            ['succeeded', $batch->succeeded_count],
            ['failed', $batch->failed_count],
            ['canceled', $batch->canceled_count],
        ]);

        if ($this->option('members')) {
            $rows = Job::where('batch_id', $batch->id)->orderBy('created_at')->get()
                ->map(fn (Job $j) => [substr((string) $j->id, 0, 8), class_basename($j->job_class), $j->state->value, $j->attempt_count.'/'.$j->max_attempts])
                ->all();
            $this->table(['job', 'class', 'state', 'att'], $rows);
        }

        return self::SUCCESS;
    }
}
