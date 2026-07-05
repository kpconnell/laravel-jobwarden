<?php

declare(strict_types=1);

namespace JobWarden\Tests\Http;

use JobWarden\JobWarden;
use JobWarden\Http\Livewire\Jobs;
use JobWarden\Models\Job;
use JobWarden\States\JobState;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Livewire\Livewire;

final class DashboardJobsTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
        JobWarden::auth(fn ($request) => true);
    }

    public function test_state_chips_multi_select_and_or_together(): void
    {
        Job::create(['job_class' => 'Alpha', 'state' => JobState::Queued, 'name' => 'alpha-run']);
        Job::create(['job_class' => 'Beta', 'state' => JobState::Failed, 'name' => 'beta-run']);
        Job::create(['job_class' => 'Gamma', 'state' => JobState::Orphaned, 'name' => 'gamma-run']);

        Livewire::test(Jobs::class)
            ->call('toggleState', 'failed')
            ->call('toggleState', 'orphaned')
            ->assertSee('beta-run')
            ->assertSee('gamma-run')
            ->assertDontSee('alpha-run')
            // deselecting narrows back
            ->call('toggleState', 'orphaned')
            ->assertDontSee('gamma-run');
    }

    public function test_lane_chip_filters_and_toggles_off(): void
    {
        Job::create(['job_class' => 'A', 'state' => JobState::Queued, 'lane' => 'default', 'name' => 'default-run']);
        Job::create(['job_class' => 'B', 'state' => JobState::Queued, 'lane' => 'scheduled', 'name' => 'scheduled-run']);

        Livewire::test(Jobs::class)
            ->call('setLane', 'scheduled')
            ->assertSee('scheduled-run')
            ->assertDontSee('default-run')
            ->call('setLane', 'scheduled')
            ->assertSee('default-run');
    }

    public function test_tag_filter_prefix_matches_values(): void
    {
        config(['jobwarden.search.promoted_params' => ['storeid']]);
        app(JobWarden::class)->dispatch('App\\Jobs\\BackfillJob', ['storeid' => 'AMAZ'], ['name' => 'match-me']);
        app(JobWarden::class)->dispatch('App\\Jobs\\BackfillJob', ['storeid' => 'WMALL'], ['name' => 'other-run']);

        Livewire::test(Jobs::class)
            ->set('tag_name', 'storeid')
            ->set('tag_value', 'AM')
            ->assertSee('match-me')
            ->assertDontSee('other-run');
    }

    public function test_search_matches_tags_and_free_text(): void
    {
        config(['jobwarden.search.promoted_params' => ['storeid']]);
        app(JobWarden::class)->dispatch('App\\Jobs\\BackfillJob', ['storeid' => 'AMAZ'], ['name' => 'match-me']);
        app(JobWarden::class)->dispatch('App\\Jobs\\BackfillJob', ['storeid' => 'WMALL'], ['name' => 'other-run']);

        Livewire::test(Jobs::class)
            ->set('q', 'storeid:AMAZ Backfill')
            ->assertSee('match-me')
            ->assertDontSee('other-run');
    }

    public function test_jobs_list_emits_a_browser_renderable_epoch(): void
    {
        // The dashboard hands the browser the absolute instant as epoch-ms (rendered into the
        // viewer's timezone client-side), never an app-timezone-formatted string. Guard that the
        // <time data-jw-epoch> carries the true epoch of the stored row.
        $at = \Illuminate\Support\Carbon::create(2026, 7, 2, 18, 0, 0, 'UTC');
        Job::create(['job_class' => 'Epochy', 'state' => JobState::Queued, 'created_at' => $at]);

        Livewire::test(Jobs::class)
            ->assertSee('Epochy')
            ->assertSee('data-jw-epoch="'.($at->getTimestamp() * 1000).'"', false);
    }

    public function test_per_page_is_clamped_to_the_allowed_sizes(): void
    {
        Job::create(['job_class' => 'X', 'state' => JobState::Queued]);

        Livewire::test(Jobs::class)
            ->set('perPage', 999)
            ->assertViewHas('jobs', fn ($jobs) => $jobs->perPage() === 25);
    }

    public function test_bulk_retry_applies_to_eligible_rows_and_skips_the_rest(): void
    {
        $failed = Job::create(['job_class' => 'F', 'state' => JobState::Failed]);
        $queued = Job::create(['job_class' => 'Q', 'state' => JobState::Queued]);

        Livewire::test(Jobs::class)
            ->set('selected', [$failed->id, $queued->id])
            ->call('bulk', 'retry')
            ->assertDispatched('jw-toast')
            ->assertSet('selected', []);

        $this->assertSame(JobState::Queued, $failed->refresh()->state); // re-queued
        $this->assertSame(JobState::Queued, $queued->refresh()->state); // skipped, untouched
    }

    public function test_bulk_cancel_skips_terminal_rows(): void
    {
        $queued = Job::create(['job_class' => 'Q', 'state' => JobState::Queued]);
        $done = Job::create(['job_class' => 'D', 'state' => JobState::Succeeded]);

        Livewire::test(Jobs::class)
            ->set('selected', [$queued->id, $done->id])
            ->call('bulk', 'cancel');

        $this->assertSame(JobState::Canceled, $queued->refresh()->state);
        $this->assertSame(JobState::Succeeded, $done->refresh()->state);
    }

    public function test_toggle_select_page_selects_and_deselects_every_visible_row(): void
    {
        $a = Job::create(['job_class' => 'A', 'state' => JobState::Queued]);
        $b = Job::create(['job_class' => 'B', 'state' => JobState::Queued]);

        Livewire::test(Jobs::class)
            ->call('toggleSelectPage')
            ->assertSet('selected', fn ($selected) => count($selected) === 2 && in_array((string) $a->id, $selected, true) && in_array((string) $b->id, $selected, true))
            ->call('toggleSelectPage')
            ->assertSet('selected', []);
    }

    public function test_changing_a_filter_clears_the_selection(): void
    {
        $job = Job::create(['job_class' => 'A', 'state' => JobState::Queued]);

        Livewire::test(Jobs::class)
            ->set('selected', [$job->id])
            ->set('q', 'anything')
            ->assertSet('selected', []);
    }
}
