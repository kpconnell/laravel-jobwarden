<?php

declare(strict_types=1);

namespace JobWarden\Tests\Search;

use JobWarden\JobWarden;
use JobWarden\Models\Job;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;

/**
 * Searchable job tags: explicit tags from the dispatcher (validated loudly at
 * the dispatch boundary) plus config-opted constructor params promoted at
 * creation (silently skipped when not a string). Search runs off the indexed
 * job_tags table via whereTag()/search().
 */
final class JobTagsTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
    }

    private function dispatch(array $params = [], array $options = []): Job
    {
        return app(JobWarden::class)->dispatch('App\\Jobs\\FakeJob', $params, $options);
    }

    private function tagsOf(Job $job): array
    {
        return $job->tags()->orderBy('name')->pluck('value', 'name')->all();
    }

    // -- explicit tags ---------------------------------------------------------

    public function test_explicit_tags_are_written_and_readable(): void
    {
        $job = $this->dispatch(['store' => 'AMAZ'], ['tags' => ['team' => 'ops', 'run' => 'nightly']]);

        $this->assertSame(['run' => 'nightly', 'team' => 'ops'], $this->tagsOf($job));
    }

    public function test_list_style_tags_are_refused_at_the_dispatch_site(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tag names must be non-empty strings');

        $this->dispatch([], ['tags' => ['reports', 'nightly']]);
    }

    public function test_an_overlong_tag_value_is_refused_at_the_dispatch_site(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 200');

        $this->dispatch([], ['tags' => ['blob' => str_repeat('x', 201)]]);
    }

    public function test_a_non_string_tag_value_is_refused_at_the_dispatch_site(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->dispatch([], ['tags' => ['count' => 42]]);
    }

    // -- param promotion ---------------------------------------------------------

    public function test_opted_in_string_params_are_promoted_to_tags(): void
    {
        config(['jobwarden.search.promoted_params' => ['storeid', 'date']]);

        $job = $this->dispatch(['storeid' => 'AMAZ', 'date' => '2025-01-15', 'limit' => 10]);

        $this->assertSame(['date' => '2025-01-15', 'storeid' => 'AMAZ'], $this->tagsOf($job));
    }

    public function test_non_string_or_overlong_values_are_silently_not_promoted(): void
    {
        config(['jobwarden.search.promoted_params' => ['count', 'flag', 'items', 'blob', 'missing']]);

        $job = $this->dispatch([
            'count' => 42,
            'flag' => true,
            'items' => ['a', 'b'],
            'blob' => str_repeat('x', 201),
        ]);

        $this->assertSame([], $this->tagsOf($job), 'promotion never throws and never coerces');
    }

    public function test_an_explicit_tag_wins_over_a_promoted_param_of_the_same_name(): void
    {
        config(['jobwarden.search.promoted_params' => ['storeid']]);

        $job = $this->dispatch(['storeid' => 'AMAZ'], ['tags' => ['storeid' => 'OVERRIDE']]);

        $this->assertSame(['storeid' => 'OVERRIDE'], $this->tagsOf($job));
    }

    // -- search ------------------------------------------------------------------

    public function test_where_tag_matches_exact_and_prefix_and_presence(): void
    {
        config(['jobwarden.search.promoted_params' => ['storeid', 'date']]);
        $a = $this->dispatch(['storeid' => 'AMAZ', 'date' => '2025-01-15']);
        $b = $this->dispatch(['storeid' => 'WMALL', 'date' => '2025-02-01']);
        $c = $this->dispatch(['other' => 'x']);

        $this->assertSame([$a->id], Job::query()->whereTag('storeid', 'AMAZ')->pluck('id')->all());
        $this->assertSame([$b->id], Job::query()->whereTag('date', '2025-02*')->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$a->id, $b->id], Job::query()->whereTag('storeid')->pluck('id')->all());
        $this->assertSame([], Job::query()->whereTag('storeid', 'NOPE')->pluck('id')->all());
        $this->assertNotNull($c);
    }

    public function test_search_tokens_and_together_and_mix_tags_with_free_text(): void
    {
        config(['jobwarden.search.promoted_params' => ['storeid']]);
        $match = app(JobWarden::class)->dispatch('App\\Jobs\\BackfillJob', ['storeid' => 'AMAZ']);
        app(JobWarden::class)->dispatch('App\\Jobs\\BackfillJob', ['storeid' => 'WMALL']);
        app(JobWarden::class)->dispatch('App\\Jobs\\OtherJob', ['storeid' => 'AMAZ']);

        $this->assertSame(
            [$match->id],
            Job::query()->search('storeid:AMAZ Backfill')->pluck('id')->all(),
        );
    }

    // -- batches -------------------------------------------------------------

    public function test_batch_members_carry_their_tags(): void
    {
        $batch = app(JobWarden::class)->batch('nightly')
            ->add('a', 'App\\Jobs\\FakeJob', ['x' => 1], [], ['tags' => ['step' => 'extract']])
            ->add('b', 'App\\Jobs\\FakeJob', ['x' => 2], ['a'])
            ->dispatch();

        $tagged = Job::query()->whereTag('step', 'extract')->get();

        $this->assertCount(1, $tagged);
        $this->assertSame($batch->id, $tagged->first()->batch_id);
    }

    public function test_a_bad_member_tag_fails_the_whole_batch_before_anything_is_created(): void
    {
        try {
            app(JobWarden::class)->batch('bad')
                ->add('a', 'App\\Jobs\\FakeJob', [], [], ['tags' => ['step' => 'ok']])
                ->add('b', 'App\\Jobs\\FakeJob', [], [], ['tags' => [42 => 'not-a-name']])
                ->dispatch();
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame(0, Job::query()->count(), 'nothing half-created');
    }

    // -- retag (backfill after a config change) -------------------------------

    public function test_retag_promotes_params_on_existing_jobs(): void
    {
        $old = $this->dispatch(['storeid' => 'AMAZ', 'count' => 3]); // dispatched BEFORE the opt-in
        $this->assertSame([], $this->tagsOf($old));

        config(['jobwarden.search.promoted_params' => ['storeid', 'count']]);
        $this->artisan('jobwarden:retag')->assertExitCode(0);

        $this->assertSame(['storeid' => 'AMAZ'], $this->tagsOf($old->fresh()));

        // Idempotent: a second run adds nothing and disturbs nothing.
        $this->artisan('jobwarden:retag')->assertExitCode(0);
        $this->assertSame(['storeid' => 'AMAZ'], $this->tagsOf($old->fresh()));
    }

    public function test_retag_never_overwrites_an_existing_tag(): void
    {
        $job = $this->dispatch(['storeid' => 'AMAZ'], ['tags' => ['storeid' => 'EXPLICIT']]);

        config(['jobwarden.search.promoted_params' => ['storeid']]);
        $this->artisan('jobwarden:retag')->assertExitCode(0);

        $this->assertSame(['storeid' => 'EXPLICIT'], $this->tagsOf($job->fresh()));
    }
}
