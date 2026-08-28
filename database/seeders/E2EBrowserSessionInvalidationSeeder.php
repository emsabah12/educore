<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class E2EBrowserSessionInvalidationSeeder extends Seeder
{
    private const EXPECTED_SESSION_DRIVER =
        'database';

    private const EXPECTED_SESSION_TABLE =
        'sessions';

    public function run(): void
    {
        /*
         * Reuse the canonical E2E database safety boundary.
         *
         * This rejects every environment/database pair except:
         *
         *   APP_ENV=e2e
         *   database=educore_e2e
         *
         * Session invalidation is intentionally destructive
         * inside that isolated database, therefore the guard
         * must execute before inspecting or mutating sessions.
         */
        E2EBrowserAuthenticationSeeder::assertSafeEnvironment();

        $driver =
            config(
                'session.driver',
            );

        if (
            $driver
                !== self::EXPECTED_SESSION_DRIVER
        ) {
            throw new RuntimeException(
                sprintf(
                    'E2E BrowserSession invalidation refused: expected session driver %s; received %s.',
                    self::EXPECTED_SESSION_DRIVER,
                    is_string($driver)
                        ? $driver
                        : get_debug_type(
                            $driver,
                        ),
                ),
            );
        }

        $table =
            config(
                'session.table',
            );

        if (
            $table
                !== self::EXPECTED_SESSION_TABLE
        ) {
            throw new RuntimeException(
                sprintf(
                    'E2E BrowserSession invalidation refused: expected session table %s; received %s.',
                    self::EXPECTED_SESSION_TABLE,
                    is_string($table)
                        ? $table
                        : get_debug_type(
                            $table,
                        ),
                ),
            );
        }

        $defaultConnection =
            config(
                'database.default',
            );

        $sessionConnection =
            config(
                'session.connection',
            );

        /*
         * The session store must remain on the same already
         * verified educore_e2e connection.
         *
         * Never allow this destructive fixture to follow an
         * independently configured external session database.
         */
        if (
            $sessionConnection
                !== null
            && $sessionConnection
                !== $defaultConnection
        ) {
            throw new RuntimeException(
                sprintf(
                    'E2E BrowserSession invalidation refused: session connection must be the default E2E database connection; received %s.',
                    is_string(
                        $sessionConnection,
                    )
                        ? $sessionConnection
                        : get_debug_type(
                            $sessionConnection,
                        ),
                ),
            );
        }

        $connection =
            DB::connection(
                is_string(
                    $sessionConnection,
                )
                    ? $sessionConnection
                    : null,
            );

        /*
         * This intentionally models authoritative server-side
         * session-store invalidation.
         *
         * It does NOT:
         * - invoke application logout,
         * - clear browser cookies,
         * - expose or revoke bearer credentials in JavaScript.
         *
         * The browser therefore keeps presenting its old cookie,
         * while Laravel can no longer resolve authenticated
         * BrowserSession state from the backing store.
         */
        $deletedSessions =
            $connection
                ->table(
                    self::EXPECTED_SESSION_TABLE,
                )
                ->delete();

        Log::info(
            'e2e.browser_session_store_invalidated',
            [
                'deleted_sessions' =>
                    $deletedSessions,

                'session_driver' =>
                    self::EXPECTED_SESSION_DRIVER,

                'session_table' =>
                    self::EXPECTED_SESSION_TABLE,
            ],
        );
    }
}
