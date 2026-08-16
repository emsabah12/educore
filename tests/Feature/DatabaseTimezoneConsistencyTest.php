<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DatabaseTimezoneConsistencyTest extends TestCase
{
    public function test_application_and_postgresql_session_use_utc(): void
    {
        $this->assertSame(
            'UTC',
            config('app.timezone'),
        );

        $connection = DB::connection();

        $this->assertSame(
            'pgsql',
            $connection->getDriverName(),
        );

        $result = $connection->selectOne(
            "SELECT current_setting('TIMEZONE') AS timezone",
        );

        $this->assertNotNull($result);
        $this->assertSame('UTC', $result->timezone);
    }
}
