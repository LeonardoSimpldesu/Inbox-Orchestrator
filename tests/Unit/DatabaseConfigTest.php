<?php

namespace Tests\Unit;

use Tests\TestCase;

class DatabaseConfigTest extends TestCase
{
    public function test_sqlite_defaults_to_wal_with_busy_timeout(): void
    {
        $sqlite = config('database.connections.sqlite');

        $this->assertSame('wal', $sqlite['journal_mode']);
        $this->assertSame(5000, $sqlite['busy_timeout']);
        $this->assertSame('normal', $sqlite['synchronous']);
    }
}
