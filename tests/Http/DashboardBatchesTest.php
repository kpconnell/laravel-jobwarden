<?php

declare(strict_types=1);

namespace JobWarden\Tests\Http;

use JobWarden\JobWarden;
use JobWarden\Http\Livewire\Batches;
use JobWarden\Http\Livewire\BatchShow;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Livewire\Livewire;

final class DashboardBatchesTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
        JobWarden::auth(fn ($request) => true);
    }

    private function jobwarden(): JobWarden
    {
        return $this->app->make(JobWarden::class);
    }

    public function test_the_list_renders_batches_with_their_policy(): void
    {
        $this->jobwarden()->batch('nightly-fanout', 'fail_fast')
            ->add('a', 'JobA')->add('b', 'JobB')->dispatch();

        Livewire::test(Batches::class)
            ->assertOk()
            ->assertSee('nightly-fanout')
            ->assertSee('fail_fast');
    }

    public function test_batch_detail_builds_the_graph(): void
    {
        $batch = $this->jobwarden()->batch('chain')
            ->add('a', 'JobA')
            ->add('b', 'JobB', [], ['a'])
            ->dispatch();

        Livewire::test(BatchShow::class, ['batch' => $batch->id])
            ->assertOk()
            ->assertSet('tab', 'graph')
            ->assertViewHas('dag', fn ($dag) => $dag['nodeCount'] === 2 && $dag['maxDepth'] === 1)
            ->assertSee('canceled downstream of a failure');
    }

    public function test_member_jobs_tab_lists_the_members(): void
    {
        $batch = $this->jobwarden()->batch('fanout')
            ->add('a', 'App\\Jobs\\AlphaJob', [], [], ['name' => 'alpha-member'])
            ->add('b', 'App\\Jobs\\BetaJob', [], [], ['name' => 'beta-member'])
            ->dispatch();

        Livewire::test(BatchShow::class, ['batch' => $batch->id])
            ->set('tab', 'jobs')
            ->assertSee('alpha-member')
            ->assertSee('beta-member');
    }

    public function test_lanes_can_be_collapsed_and_expanded(): void
    {
        $batch = $this->jobwarden()->batch('fanout')
            ->add('a', 'JobA')->add('b', 'JobB')->dispatch();

        $component = Livewire::test(BatchShow::class, ['batch' => $batch->id]);
        // Small batch: every lane auto-expands on first render.
        $this->assertCount(2, $component->get('expandedLanes'));

        $component->call('collapseAll')
            ->assertSet('expandedLanes', [])
            ->assertSee('click to expand')
            ->call('expandAll');
        $this->assertCount(2, $component->get('expandedLanes'));
    }

    public function test_cancel_batch_from_the_detail_page(): void
    {
        $batch = $this->jobwarden()->batch('b')->add('a', 'JobA')->dispatch();

        Livewire::test(BatchShow::class, ['batch' => $batch->id])
            ->call('cancel')
            ->assertDispatched('jw-toast');

        $this->assertSame('canceled', $batch->refresh()->state->value);
    }
}
