<?php

declare(strict_types=1);

namespace JobWarden\Tests\Batch;

use JobWarden\Batch\BatchDag;
use JobWarden\JobWarden;
use JobWarden\Models\Batch;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Illuminate\Support\Facades\DB;

final class BatchDagTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
    }

    private function jobwarden(): JobWarden
    {
        return $this->app->make(JobWarden::class);
    }

    private function setState(Batch $batch, string $jobClass, string $state): void
    {
        DB::connection(config('jobwarden.connection'))
            ->table(config('jobwarden.table_prefix').'jobs')
            ->where('batch_id', $batch->id)->where('job_class', $jobClass)
            ->update(['state' => $state]);
    }

    /** @return array<string, array> nodes of the single lane, keyed by label */
    private function nodesByLabel(array $dag): array
    {
        $nodes = [];
        foreach ($dag['lanes'] as $lane) {
            foreach ($lane['nodes'] as $n) {
                $nodes[$n['label']] = $n;
            }
        }

        return $nodes;
    }

    public function test_chain_depths_follow_the_dependency_edges(): void
    {
        $batch = $this->jobwarden()->batch('chain')
            ->add('a', 'JobA')
            ->add('b', 'JobB', [], ['a'])
            ->add('c', 'JobC', [], ['b'])
            ->dispatch();

        $dag = BatchDag::build($batch->refresh());

        $this->assertSame(3, $dag['nodeCount']);
        $this->assertSame(2, $dag['maxDepth']);
        $this->assertCount(1, $dag['lanes']);

        $nodes = $this->nodesByLabel($dag);
        $this->assertSame(0, $nodes['a']['depth']);
        $this->assertSame(1, $nodes['b']['depth']);
        $this->assertSame(2, $nodes['c']['depth']);
    }

    public function test_a_diamond_takes_the_longest_path_depth(): void
    {
        $batch = $this->jobwarden()->batch('diamond')
            ->add('root', 'Root')
            ->add('fast', 'Fast', [], ['root'])
            ->add('slow1', 'Slow1', [], ['root'])
            ->add('slow2', 'Slow2', [], ['slow1'])
            ->add('join', 'Join', [], ['fast', 'slow2'])
            ->dispatch();

        $nodes = $this->nodesByLabel(BatchDag::build($batch->refresh()));

        $this->assertSame(3, $nodes['join']['depth']); // via root → slow1 → slow2 → join
    }

    public function test_independent_subchains_become_separate_lanes(): void
    {
        $batch = $this->jobwarden()->batch('fanout')
            ->add('a1', 'JobA1')->add('a2', 'JobA2', [], ['a1'])
            ->add('b1', 'JobB1')->add('b2', 'JobB2', [], ['b1'])
            ->add('solo', 'Solo')
            ->dispatch();

        $dag = BatchDag::build($batch->refresh());

        $this->assertCount(3, $dag['lanes']);
        $this->assertSame(5, $dag['nodeCount']);
    }

    public function test_lanes_are_labeled_by_the_tag_that_distinguishes_them(): void
    {
        $batch = $this->jobwarden()->batch('stores')
            ->add('a1', 'Sync', [], [], ['tags' => ['storeid' => 'AMAZ']])
            ->add('a2', 'Report', [], ['a1'], ['tags' => ['storeid' => 'AMAZ']])
            ->add('w1', 'Sync', [], [], ['tags' => ['storeid' => 'WMALL']])
            ->add('w2', 'Report', [], ['w1'], ['tags' => ['storeid' => 'WMALL']])
            ->dispatch();

        $labels = array_column(BatchDag::build($batch->refresh())['lanes'], 'label');
        sort($labels);

        $this->assertSame(['AMAZ', 'WMALL'], $labels);
    }

    public function test_nodes_downstream_of_a_failure_are_dimmed_and_the_lane_sorts_first(): void
    {
        $batch = $this->jobwarden()->batch('ff', 'fail_fast')
            ->add('ok1', 'Healthy1')->add('ok2', 'Healthy2', [], ['ok1'])
            ->add('boom', 'Boom')->add('tail1', 'Tail1', [], ['boom'])->add('tail2', 'Tail2', [], ['tail1'])
            ->dispatch();

        $this->setState($batch, 'Boom', 'failed');
        $this->setState($batch, 'Tail1', 'canceled');
        $this->setState($batch, 'Tail2', 'canceled');

        $dag = BatchDag::build($batch->refresh());
        $nodes = $this->nodesByLabel($dag);

        $this->assertFalse($nodes['boom']['dimmed']); // the failure itself stays loud
        $this->assertTrue($nodes['tail1']['dimmed']);
        $this->assertTrue($nodes['tail2']['dimmed']);
        $this->assertFalse($nodes['ok1']['dimmed']);
        $this->assertFalse($nodes['ok2']['dimmed']);

        $this->assertTrue($dag['lanes'][0]['failing']); // failing lane first
        $this->assertSame(['failed' => 1, 'canceled' => 2], array_intersect_key($dag['lanes'][0]['states'], ['failed' => 1, 'canceled' => 1]));
    }

    public function test_stage_labels_summarize_the_classes_at_each_depth(): void
    {
        $batch = $this->jobwarden()->batch('stages')
            ->add('a', 'App\\Jobs\\SyncJob')
            ->add('b', 'App\\Jobs\\ReportJob', [], ['a'])
            ->dispatch();

        $dag = BatchDag::build($batch->refresh());

        $this->assertSame(['SyncJob', 'ReportJob'], $dag['stages']);
    }

    public function test_an_empty_batch_builds_an_empty_graph(): void
    {
        $batch = Batch::create(['name' => 'empty', 'state' => 'pending', 'failure_policy' => 'continue']);

        $dag = BatchDag::build($batch);

        $this->assertSame(0, $dag['nodeCount']);
        $this->assertSame([], $dag['lanes']);
    }
}
