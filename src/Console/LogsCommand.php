<?php

declare(strict_types=1);

namespace JobWarden\Console;

use JobWarden\Logging\Contracts\LogBodySink;
use JobWarden\Logging\JobExporter;
use JobWarden\Models\Job;
use JobWarden\Models\JobLog;
use Illuminate\Console\Command;

/**
 * Tail/inspect/export a job's logs (spec §9.3). `--follow` polls the LogIndex by a
 * monotonic id cursor so you can watch a RUNNING job's logs live; `--export` emits
 * a self-contained NDJSON support bundle (job + attempts + events + logs + artifacts).
 */
final class LogsCommand extends Command
{
    protected $signature = 'jobwarden:logs {job : job id or prefix} {--follow : live-tail} {--export : NDJSON support bundle} {--lines=200 : initial lines}';

    protected $description = 'Show, follow, or export a job\'s logs.';

    public function handle(LogBodySink $sink, JobExporter $exporter): int
    {
        $job = $this->resolveJob((string) $this->argument('job'));
        if ($job === null) {
            $this->error('job not found');

            return self::FAILURE;
        }

        if ($this->option('export')) {
            foreach ($exporter->export($job) as $line) {
                $this->output->write($line);
            }

            return self::SUCCESS;
        }

        $cursor = 0;

        // Initial backfill (last N lines).
        $initial = JobLog::where('job_id', $job->id)->orderByDesc('id')->limit((int) $this->option('lines'))->get()->reverse();
        foreach ($initial as $row) {
            $this->render($row, $sink);
            $cursor = max($cursor, (int) $row->id);
        }

        if (! $this->option('follow')) {
            return self::SUCCESS;
        }

        while (true) {
            $rows = JobLog::where('job_id', $job->id)->where('id', '>', $cursor)->orderBy('id')->get();
            foreach ($rows as $row) {
                $this->render($row, $sink);
                $cursor = (int) $row->id;
            }

            $job->refresh();
            if ($job->state->isTerminal() && $rows->isEmpty()) {
                $this->line("<fg=gray>— job {$job->state->value} —</>");
                break;
            }

            usleep(400_000);
        }

        return self::SUCCESS;
    }

    private function render(JobLog $row, LogBodySink $sink): void
    {
        $ts = $row->ts?->format('H:i:s.v') ?? '';
        $level = str_pad(strtoupper($row->level), 8);
        $step = $row->step ? "[{$row->step}] " : '';
        $body = $sink->resolve((string) $row->body_ref) ?? '';
        $color = match ($row->level) {
            'error', 'critical', 'alert', 'emergency' => 'red',
            'warning' => 'yellow',
            'notice' => 'cyan',
            default => 'white',
        };

        $this->line("<fg=gray>{$ts}</> <fg={$color}>{$level}</> {$step}{$body}");
    }

    private function resolveJob(string $idOrPrefix): ?Job
    {
        return Job::where('id', $idOrPrefix)->first()
            ?? Job::where('id', 'like', $idOrPrefix.'%')->orderByDesc('created_at')->first();
    }
}
