<?php

declare(strict_types=1);

namespace JobWarden\Tests\Smoke;

use JobWarden\States\ActorType;
use JobWarden\States\AttemptState;
use JobWarden\States\JobState;
use JobWarden\Tests\TestCase;
use Illuminate\Support\Facades\DB;

final class PackageBootTest extends TestCase
{
    public function test_config_is_merged_and_connection_is_named(): void
    {
        $this->assertSame('jobwarden', config('jobwarden.connection'));
        $this->assertSame('jobwarden_', config('jobwarden.table_prefix'));
        $this->assertSame('uuid7', config('jobwarden.id.strategy'));
    }

    public function test_dedicated_jobwarden_connection_is_reachable(): void
    {
        $row = DB::connection(config('jobwarden.connection'))->select('select 1 as one');

        $this->assertSame(1, (int) $row[0]->one);
    }

    public function test_state_enums_expose_terminality(): void
    {
        $this->assertTrue(JobState::Succeeded->isTerminal());
        $this->assertFalse(JobState::Running->isTerminal());

        // orphaned is an indeterminate limbo — re-enterable, never terminal.
        $this->assertFalse(AttemptState::Orphaned->isTerminal());
        $this->assertTrue(AttemptState::Dispatched->isInFlight());

        $this->assertSame('system', ActorType::System->value);
    }
}
