<?php

declare(strict_types=1);

namespace Workbench\App\Console;

use JobWarden\JobWarden;
use Illuminate\Console\Command;
use Workbench\App\Jobs\FailingJob;
use Workbench\App\Jobs\MarkerJob;

/**
 * Dispatch sample batches for manual / integration testing.
 *
 *   jobwarden:demo:batch fanout --size=6
 *   jobwarden:demo:batch chain
 *   jobwarden:demo:batch failfast
 */
final class DemoBatchCommand extends Command
{
    protected $signature = 'jobwarden:demo:batch {kind=fanout : fanout|chain|failfast|diamond|failchain|failfanin} {--size=5} {--sleep=1}';

    protected $description = 'Dispatch a sample batch (fan-out / chain / DAG / failure shapes).';

    public function handle(JobWarden $jobwarden): int
    {
        $sleep = (int) $this->option('sleep');
        $kind = (string) $this->argument('kind');
        $m = fn (int $s = null) => ['sleep' => $s ?? $sleep];
        $idem = ['idempotent' => true];

        if ($kind === 'chain') {
            $batch = $jobwarden->batch('demo-chain')
                ->add('a', MarkerJob::class, $m(), [], $idem)
                ->add('b', MarkerJob::class, $m(), ['a'], $idem)
                ->add('c', MarkerJob::class, $m(), ['b'], $idem)
                ->dispatch();
        } elseif ($kind === 'diamond') {
            // A → {B, C} → D : fan-out then fan-in (a diamond DAG).
            $batch = $jobwarden->batch('demo-diamond')
                ->add('a', MarkerJob::class, $m(), [], $idem)
                ->add('b', MarkerJob::class, $m(), ['a'], $idem)
                ->add('c', MarkerJob::class, $m(), ['a'], $idem)
                ->add('d', MarkerJob::class, $m(), ['b', 'c'], $idem)
                ->dispatch();
        } elseif ($kind === 'failchain') {
            // continue policy; root fails → b, c are unreachable and cascade-cancel.
            $batch = $jobwarden->batch('demo-failchain', 'continue')
                ->add('a', FailingJob::class, ['message' => 'root failed'], [], ['idempotent' => false])
                ->add('b', MarkerJob::class, $m(), ['a'], $idem)
                ->add('c', MarkerJob::class, $m(), ['b'], $idem)
                ->dispatch();
        } elseif ($kind === 'failfanin') {
            // continue; d joins a+b. b fails → d unreachable (even though a succeeds).
            $batch = $jobwarden->batch('demo-failfanin', 'continue')
                ->add('a', MarkerJob::class, $m(), [], $idem)
                ->add('b', FailingJob::class, ['message' => 'one input failed'], [], ['idempotent' => false])
                ->add('d', MarkerJob::class, $m(), ['a', 'b'], $idem)
                ->dispatch();
        } elseif ($kind === 'failfast') {
            $builder = $jobwarden->batch('demo-failfast', 'fail_fast')
                ->add('boom', FailingJob::class, ['message' => 'demo fail_fast'], [], ['idempotent' => false]);
            for ($i = 0; $i < 4; $i++) {
                $builder->add("slow{$i}", MarkerJob::class, ['sleep' => 8], [], ['idempotent' => true]);
            }
            $batch = $builder->dispatch();
        } else {
            $builder = $jobwarden->batch('demo-fanout', 'continue');
            for ($i = 0; $i < (int) $this->option('size'); $i++) {
                $builder->add("j{$i}", MarkerJob::class, ['sleep' => $sleep], [], ['idempotent' => true]);
            }
            $batch = $builder->dispatch();
        }

        $this->info("dispatched {$kind} batch {$batch->id} ({$batch->total_jobs} members)");

        return self::SUCCESS;
    }
}
