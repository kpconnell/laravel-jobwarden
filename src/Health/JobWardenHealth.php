<?php

declare(strict_types=1);

namespace JobWarden\Health;

use JobWarden\States\AttemptState;
use JobWarden\States\JobState;
use JobWarden\Support\SqlTime;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * The aggregate-integrity invariants that MUST hold across jobs + attempts. This
 * is the correctness tripwire: assert `invariantViolations() === []` at the end
 * of chaos tests, and surface it as an operator health signal. Every violation
 * describes a job whose durable state is internally inconsistent — the class of
 * bug this engine exists to prevent.
 */
final class JobWardenHealth
{
    /**
     * @return list<array{invariant: string, job_id: string, detail: string}>
     *
     * @param int $graceSeconds Ignore jobs whose current attempt settled within
     *                          this window — the sub-millisecond gap a healthy
     *                          worker occupies between its two transitions. Pass 0
     *                          for a strict check (tests); pass the reconcile grace
     *                          for a live health signal.
     */
    public function invariantViolations(int $graceSeconds = 0): array
    {
        return array_merge(
            $this->runningJobsWithSettledAttempt($graceSeconds),
            $this->jobsWithMissingCurrentAttempt(),
            $this->jobsWithAttemptCountBelowMax(),
        );
    }

    public function isConsistent(int $graceSeconds = 0): bool
    {
        return $this->invariantViolations($graceSeconds) === [];
    }

    /**
     * The stranding signature: a `running` job whose current attempt already
     * settled. A running job MUST have an in-flight (dispatched/running) current
     * attempt — anything else means the job never resolved after its attempt did.
     *
     * @return list<array{invariant: string, job_id: string, detail: string}>
     */
    private function runningJobsWithSettledAttempt(int $graceSeconds): array
    {
        $conn = $this->connection();

        $rows = $conn->table($this->tbl('jobs').' as j')
            ->join($this->tbl('job_attempts').' as a', 'a.id', '=', 'j.current_attempt_id')
            ->where('j.state', JobState::Running->value)
            ->whereNotIn('a.state', [AttemptState::Dispatched->value, AttemptState::Running->value])
            ->whereRaw('COALESCE(a.finished_at, a.updated_at) < '.SqlTime::nowMinus($conn, $graceSeconds))
            ->orderBy('j.id')
            ->limit(1000)
            ->get(['j.id as job_id', 'a.state as attempt_state']);

        return $rows->map(static fn ($r): array => [
            'invariant' => 'running_job_with_settled_attempt',
            'job_id' => (string) $r->job_id,
            'detail' => "job is running but current attempt is '{$r->attempt_state}'",
        ])->all();
    }

    /**
     * A job pointing at a current attempt row that does not exist — a dangling
     * reference (should be impossible; deeper corruption if seen).
     *
     * @return list<array{invariant: string, job_id: string, detail: string}>
     */
    private function jobsWithMissingCurrentAttempt(): array
    {
        $conn = $this->connection();

        $rows = $conn->table($this->tbl('jobs').' as j')
            ->leftJoin($this->tbl('job_attempts').' as a', 'a.id', '=', 'j.current_attempt_id')
            ->whereNotNull('j.current_attempt_id')
            ->whereNull('a.id')
            ->orderBy('j.id')
            ->limit(1000)
            ->pluck('j.id');

        return $rows->map(static fn ($id): array => [
            'invariant' => 'job_current_attempt_missing',
            'job_id' => (string) $id,
            'detail' => 'current_attempt_id points at a non-existent attempt',
        ])->all();
    }

    /**
     * attempt_count must always cover the highest attempt_number ever minted for
     * the job; a lower count means a claim minted an attempt without bumping it.
     *
     * @return list<array{invariant: string, job_id: string, detail: string}>
     */
    private function jobsWithAttemptCountBelowMax(): array
    {
        $conn = $this->connection();

        $maxByJob = $conn->table($this->tbl('job_attempts'))
            ->select('job_id', $conn->raw('MAX(attempt_number) as mx'))
            ->groupBy('job_id');

        $rows = $conn->table($this->tbl('jobs').' as j')
            ->joinSub($maxByJob, 'm', 'm.job_id', '=', 'j.id')
            ->whereColumn('j.attempt_count', '<', 'm.mx')
            ->orderBy('j.id')
            ->limit(1000)
            ->get(['j.id as job_id', 'j.attempt_count', 'm.mx']);

        return $rows->map(static fn ($r): array => [
            'invariant' => 'attempt_count_below_max_number',
            'job_id' => (string) $r->job_id,
            'detail' => "attempt_count={$r->attempt_count} < max(attempt_number)={$r->mx}",
        ])->all();
    }

    private function connection(): Connection
    {
        return DB::connection(config('jobwarden.connection'));
    }

    private function tbl(string $name): string
    {
        return ((string) config('jobwarden.table_prefix')).$name;
    }
}
