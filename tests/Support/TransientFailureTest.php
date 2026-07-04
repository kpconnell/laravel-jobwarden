<?php

declare(strict_types=1);

namespace JobWarden\Tests\Support;

use JobWarden\Support\TransientFailure;
use JobWarden\Tests\TestCase;
use Illuminate\Database\QueryException;

/**
 * The loop-survival classifier: transient infrastructure failure (outlast it)
 * vs deterministic failure (die loudly). Backed by Laravel's own lost-connection
 * and concurrency-error needle lists, plus a previous-chain walk for wrapping.
 */
final class TransientFailureTest extends TestCase
{
    public function test_a_lost_connection_is_transient(): void
    {
        $this->assertTrue(TransientFailure::isTransient(
            new \PDOException('SQLSTATE[HY000] [2006] MySQL server has gone away')
        ));
    }

    public function test_a_deadlock_is_transient(): void
    {
        $this->assertTrue(TransientFailure::isTransient(
            new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock')
        ));
    }

    public function test_a_lock_wait_timeout_is_transient(): void
    {
        $this->assertTrue(TransientFailure::isTransient(
            new \PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction')
        ));
    }

    public function test_a_wrapped_lost_connection_is_recognized_through_the_previous_chain(): void
    {
        $this->assertTrue(TransientFailure::isTransient(
            new \RuntimeException('tick failed', 0, new \PDOException('SQLSTATE[HY000] [2006] MySQL server has gone away'))
        ));
    }

    public function test_a_query_exception_wrapping_a_deadlock_is_transient(): void
    {
        $this->assertTrue(TransientFailure::isTransient(new QueryException(
            'jobwarden',
            'update jobs set state = ?',
            ['running'],
            new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock')
        )));
    }

    public function test_a_schema_bug_is_deterministic(): void
    {
        // Migration drift throws QueryExceptions too — but recurs every tick, so
        // it must NOT be classified as weather.
        $this->assertFalse(TransientFailure::isTransient(new QueryException(
            'jobwarden',
            'select missing from jobs',
            [],
            new \PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'missing' in 'field list'")
        )));
    }

    public function test_a_code_bug_is_deterministic(): void
    {
        $this->assertFalse(TransientFailure::isTransient(new \TypeError('argument #1 must be of type int')));
        $this->assertFalse(TransientFailure::isTransient(new \RuntimeException('boom')));
    }
}
