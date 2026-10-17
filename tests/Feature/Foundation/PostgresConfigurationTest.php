<?php

namespace Tests\Feature\Foundation;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgresConfigurationTest extends TestCase
{
    public function test_test_suite_uses_postgresql_test_database(): void
    {
        $version = DB::selectOne('select version() as version')->version;

        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('flexee_finance_v3', config('database.connections.pgsql.database'));
        $this->assertStringStartsWith('PostgreSQL', $version);
    }
}
