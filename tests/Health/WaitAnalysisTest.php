<?php

declare(strict_types=1);

namespace JobWarden\Tests\Health;

use JobWarden\Health\WaitAnalysis;
use JobWarden\JobWarden;
use JobWarden\Models\Batch;
use JobWarden\Models\Job;
use JobWarden\Models\Worker;
use JobWarden\States\BatchState;
use JobWarden\States\JobState;
use JobWarden\Support\SqlTime;
use JobWarden\Tests\Concerns\RefreshesJobWardenSchema;
use JobWarden\Tests\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * WaitAnalysis answers "why hasn't this job started?", so its whole value is
 * agreeing with the engine. These tests pin it to the same predicates the
 * Admitter and the claim actually apply — an answer that drifts from them is
 * worse than no answer.
 */
final class WaitAnalysisTest extends TestCase
{
    use RefreshesJobWardenSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJobWardenSchema();
    }

    public function test_it_reports_nothing_for_a_job_that_is_not_waiting(): void
    {
        foreach ([JobState::Running, JobState::Succeeded, JobState::Failed, JobState::Orphaned] as $state) {
            $job = Job::create(['job_class' => 'X', 'state' => $state]);
            $this->assertNull(WaitAnalysis::for($job), "expected no analysis for {$state->value}");
        }
    }

    public function test_a_pending_job_lists_only_its_unmet_upstream_jobs(): void
    {
        $this->supervisor();
        $blocking = Job::create(['job_class' => 'Slow', 'state' => JobState::Running]);
        $done = Job::create(['job_class' => 'Done', 'state' => JobState::Succeeded]);
        $job = Job::create(['job_class' => 'Downstream', 'state' => JobState::Pending]);
        $this->edge($job, $blocking);
        $this->edge($job, $done);

        $w = WaitAnalysis::for($job);

        $this->assertCount(1, $w['job_deps']['items']);
        $this->assertSame($blocking->id, $w['job_deps']['items'][0]['id']);
        $this->assertSame('Slow', $w['job_deps']['items'][0]['label']);
        $this->assertSame('Blocked on 1 upstream job', $w['headline']['text']);
    }

    /**
     * The on_completion carve-out: a finally-style member runs after its
     * upstream ENDS, however it ended.
     */
    public function test_an_on_completion_edge_is_satisfied_by_any_terminal_upstream(): void
    {
        $this->supervisor();
        $failed = Job::create(['job_class' => 'Failed', 'state' => JobState::Failed]);

        $finally = Job::create(['job_class' => 'Finally', 'state' => JobState::Pending]);
        $this->edge($finally, $failed, 'on_completion');
        $this->assertSame([], WaitAnalysis::for($finally)['job_deps']['items']);

        $strict = Job::create(['job_class' => 'Strict', 'state' => JobState::Pending]);
        $this->edge($strict, $failed);
        $this->assertCount(1, WaitAnalysis::for($strict)['job_deps']['items']);
    }

    /**
     * `orphaned` is NOT terminal — a parked orphan awaits an operator verdict —
     * so it gates dependents under either condition. This is the blocker the
     * panel exists to explain, so it had better report it.
     */
    public function test_an_orphaned_upstream_gates_even_an_on_completion_edge(): void
    {
        $this->supervisor();
        $orphan = Job::create(['job_class' => 'Orphan', 'state' => JobState::Orphaned]);
        $job = Job::create(['job_class' => 'Downstream', 'state' => JobState::Pending]);
        $this->edge($job, $orphan, 'on_completion');

        $w = WaitAnalysis::for($job);

        $this->assertCount(1, $w['job_deps']['items']);
        $this->assertSame('orphaned', $w['job_deps']['items'][0]['state']);
    }

    public function test_a_delayed_job_reports_the_availability_gate(): void
    {
        $this->supervisor();
        $job = app(JobWarden::class)->dispatch('App\\Jobs\\Later', [], ['delay' => 300]);

        $this->assertSame(JobState::Pending, $job->state);

        $w = WaitAnalysis::for($job);

        $this->assertFalse($w['due']['ok']);
        $this->assertNotNull($w['due']['at_ms']);
        $this->assertSame('Not due — scheduled for', $w['headline']['text']);
    }

    public function test_a_pending_job_with_no_live_supervisor_says_nothing_is_admitting(): void
    {
        $job = Job::create(['job_class' => 'X', 'state' => JobState::Pending]);

        $w = WaitAnalysis::for($job);

        $this->assertSame(0, $w['lane']['fleet']);
        $this->assertSame('red', $w['headline']['tone']);
        $this->assertSame('No live supervisor — nothing is running the admit pass', $w['headline']['text']);
    }

    /**
     * Admission is not lane-scoped (Admitter::promote has no lane filter), so a
     * supervisor on ANY lane keeps a pending job moving — while the lane row
     * still warns that it will strand once queued.
     */
    public function test_admission_only_needs_a_supervisor_somewhere_in_the_fleet(): void
    {
        $this->supervisor('default');
        $job = Job::create(['job_class' => 'X', 'state' => JobState::Pending, 'lane' => 'reports']);

        $w = WaitAnalysis::for($job);

        $this->assertSame(1, $w['lane']['fleet']);
        $this->assertSame(0, $w['lane']['supervisors']);
        $this->assertSame('Eligible — awaiting the next admit pass', $w['headline']['text']);
    }

    public function test_a_queued_job_on_an_uncovered_lane_is_called_out(): void
    {
        $this->supervisor('default');
        $job = Job::create(['job_class' => 'X', 'state' => JobState::Queued, 'lane' => 'reports']);

        $w = WaitAnalysis::for($job);

        $this->assertSame(0, $w['lane']['supervisors']);
        $this->assertSame('red', $w['headline']['tone']);
        $this->assertSame('No supervisor is running lane reports', $w['headline']['text']);
    }

    /**
     * A row still in `active` whose lease has expired is a process that stopped
     * claiming. The global reaper will flip it to `dead`, but until it does,
     * counting it as coverage would hide the outage.
     */
    public function test_a_supervisor_past_its_lease_is_not_coverage(): void
    {
        $ttl = (int) config('jobwarden.host_lease.heartbeat_interval')
            * (int) config('jobwarden.host_lease.missed_beats');
        $this->supervisor('reports', lastBeatSec: $ttl + 30);
        $job = Job::create(['job_class' => 'X', 'state' => JobState::Queued, 'lane' => 'reports']);

        $w = WaitAnalysis::for($job);

        $this->assertSame(0, $w['lane']['supervisors']);
        $this->assertSame(1, $w['lane']['stale']);
        $this->assertSame(0, $w['lane']['capacity']);
        $this->assertSame('Lane reports has no live supervisor — 1 present but past its lease', $w['headline']['text']);
    }

    public function test_a_saturated_lane_reports_its_slots(): void
    {
        $this->supervisor('default', capacity: 2, load: 2);
        $job = Job::create(['job_class' => 'X', 'state' => JobState::Queued]);

        $w = WaitAnalysis::for($job);

        $this->assertSame(0, $w['lane']['free']);
        $this->assertSame('Lane default is saturated — all 2 slots busy', $w['headline']['text']);
    }

    public function test_a_lone_queued_job_is_next_up(): void
    {
        $this->supervisor();
        $job = Job::create(['job_class' => 'X', 'state' => JobState::Queued]);

        $w = WaitAnalysis::for($job);

        $this->assertSame(['count' => 0, 'capped' => false], $w['ahead']);
        $this->assertSame('Next up on lane default', $w['headline']['text']);
    }

    /**
     * "Ahead" must mean exactly what the claim means: higher priority, or the
     * same priority and older. Same lane only, queued only.
     */
    public function test_the_backlog_counts_only_what_the_claim_takes_first(): void
    {
        $this->supervisor();

        $this->queuedAt(seconds: 60);                          // older, same priority  → ahead
        $this->queuedAt(seconds: 50);                          // older, same priority  → ahead
        $this->queuedAt(seconds: 5, priority: 10);             // newer but higher       → ahead
        $this->queuedAt(seconds: 1);                           // newer, same priority   → behind
        $this->queuedAt(seconds: 90, priority: -5);            // older but lower        → behind
        $this->queuedAt(seconds: 90, lane: 'reports');         // other lane             → irrelevant
        $this->queuedAt(seconds: 90, state: JobState::Pending); // not queued yet        → irrelevant

        $job = $this->queuedAt(seconds: 10);

        $w = WaitAnalysis::for($job);

        $this->assertSame(['count' => 3, 'capped' => false], $w['ahead']);
        $this->assertSame('3 jobs ahead on lane default', $w['headline']['text']);
    }

    public function test_the_backlog_count_stops_at_the_cap(): void
    {
        $this->supervisor();
        $job = $this->queuedAt(seconds: 10);

        // Higher priority, so every one of them claims first regardless of age.
        $rows = [];
        for ($i = 0; $i < WaitAnalysis::AHEAD_CAP + 5; $i++) {
            $rows[] = [
                'id' => (string) Uuid::v7(),
                'job_class' => 'Ahead',
                'state' => JobState::Queued->value,
                'lane' => 'default',
                'priority' => 10,
            ];
        }
        $this->jobwarden()->table($this->tbl('jobs'))->insert($rows);

        $this->assertSame(
            ['count' => WaitAnalysis::AHEAD_CAP, 'capped' => true],
            WaitAnalysis::for($job)['ahead']
        );
    }

    public function test_a_retrying_job_reports_its_backoff(): void
    {
        $this->supervisor();
        $job = Job::create([
            'job_class' => 'Flaky', 'state' => JobState::Retrying,
            'attempt_count' => 2, 'max_attempts' => 4,
        ]);
        $this->setAvailableAt($job, 60);

        $w = WaitAnalysis::for($job);

        $this->assertFalse($w['due']['ok']);
        $this->assertSame('Backoff (attempt 2 of 4) — retries', $w['headline']['text']);
        $this->assertSame('amber', $w['headline']['tone']);
        // Retries are deliberately not dep-guarded, so no edges are consulted.
        $this->assertSame([], $w['job_deps']['items']);
    }

    public function test_an_unmet_batch_dependency_carries_the_upstream_in_flight_count(): void
    {
        $this->supervisor();
        $upstream = Batch::create([
            'name' => 'nightly-extract', 'state' => BatchState::Running,
            'total_jobs' => 3, 'pending_count' => 1, 'running_count' => 1,
        ]);

        $job = app(JobWarden::class)->dispatch('App\\Jobs\\Rollup', [], ['depends_on_batches' => $upstream]);

        $w = WaitAnalysis::for($job);

        $this->assertCount(1, $w['batch_deps']['items']);
        $this->assertSame('nightly-extract', $w['batch_deps']['items'][0]['label']);
        $this->assertSame(2, $w['batch_deps']['items'][0]['in_flight']);
        $this->assertSame('Blocked on 1 upstream batch', $w['headline']['text']);
    }

    public function test_a_requested_cancel_takes_over_the_headline(): void
    {
        $this->supervisor();
        $job = Job::create([
            'job_class' => 'X', 'state' => JobState::Queued,
            'cancel_requested' => true, 'cancel_mode' => 'stop',
        ]);

        $this->assertSame(
            'stop requested — this job will not start',
            WaitAnalysis::for($job)['headline']['text']
        );
    }

    // -- fixtures ---------------------------------------------------------

    /** A supervisor whose heartbeat is $lastBeatSec old (0 = beating now). */
    private function supervisor(string $lane = 'default', int $capacity = 5, int $load = 0, int $lastBeatSec = 0): Worker
    {
        $worker = Worker::create([
            'role' => 'supervisor',
            'host_id' => 'host-'.Uuid::v7(),
            'state' => 'active',
            'incarnation' => 1,
            'capacity' => $capacity,
            'current_load' => $load,
            'meta' => ['lane' => $lane],
        ]);

        $conn = $this->jobwarden();
        $conn->table($worker->getTable())->where('id', $worker->id)->update([
            'heartbeat_at' => $conn->raw($lastBeatSec > 0
                ? SqlTime::nowMinus($conn, $lastBeatSec)
                : SqlTime::nowExpr($conn)),
        ]);

        return $worker;
    }

    private function edge(Job $job, Job $upstream, string $condition = 'on_success'): void
    {
        $this->jobwarden()->table($this->tbl('job_dependencies'))->insert([
            'job_id' => $job->id,
            'depends_on_job_id' => $upstream->id,
            'edge_condition' => $condition,
        ]);
    }

    /**
     * A job created $seconds ago on the DB clock. created_at must be stamped in
     * SQL: CURRENT_TIMESTAMP is second-resolution on SQLite, so rows created
     * back-to-back would otherwise tie and the age comparison would prove nothing.
     */
    private function queuedAt(int $seconds, int $priority = 0, string $lane = 'default', ?JobState $state = null): Job
    {
        $job = Job::create([
            'job_class' => 'Backlog',
            'state' => $state ?? JobState::Queued,
            'lane' => $lane,
            'priority' => $priority,
        ]);

        $conn = $this->jobwarden();
        $conn->table($job->getTable())->where('id', $job->id)->update([
            'created_at' => $conn->raw(SqlTime::nowMinus($conn, $seconds)),
        ]);

        return $job->refresh();
    }

    private function setAvailableAt(Job $job, int $secondsFromNow): void
    {
        $conn = $this->jobwarden();
        $conn->table($job->getTable())->where('id', $job->id)->update([
            'available_at' => $conn->raw(SqlTime::nowPlus($conn, $secondsFromNow)),
        ]);
    }
}
