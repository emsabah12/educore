<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Unit;

use InvalidArgumentException;
use Modules\Core\Identity\Models\User;
use Tests\TestCase;

final class UserUsernameWriteBoundaryTest extends TestCase
{
    public function test_username_is_explicitly_fillable_on_canonical_user(): void
    {
        $user = new User();

        $this->assertTrue(
            $user->isFillable('username'),
            'Canonical User writes must explicitly allow the username attribute.',
        );
    }

    public function test_username_is_trimmed_and_lowercased_on_assignment(): void
    {
        $user = new User();

        $user->username = '  Admin.User-01  ';

        $this->assertSame(
            'admin.user-01',
            $user->getAttribute('username'),
            'Username must be stored in canonical trimmed lowercase form.',
        );
    }

    public function test_null_username_remains_null(): void
    {
        $user = new User();

        $user->username = null;

        $this->assertNull(
            $user->getAttribute('username'),
            'Username is optional and explicit null must remain null.',
        );
    }

    public function test_valid_canonical_usernames_are_accepted(): void
    {
        $validUsernames = [
            'abc',
            'admin.user',
            'admin_user',
            'admin-user',
            'a.b_c-d9',
            str_repeat('a', 64),
        ];

        foreach ($validUsernames as $username) {
            $user = new User();

            $user->username = $username;

            $this->assertSame(
                $username,
                $user->getAttribute('username'),
                sprintf(
                    'Expected valid canonical username [%s] to be accepted.',
                    $username,
                ),
            );
        }
    }

    public function test_invalid_usernames_are_rejected_at_canonical_write_boundary(): void
    {
        $invalidUsernames = [
            '',
            '   ',
            'ab',
            str_repeat('a', 65),
            '.admin',
            '_admin',
            '-admin',
            'admin.',
            'admin_',
            'admin-',
            'admin@example',
            'admin user',
            'admin/user',
            'admin+user',
        ];

        $unexpectedlyAccepted = [];

        foreach ($invalidUsernames as $username) {
            $user = new User();

            try {
                $user->username = $username;

                $unexpectedlyAccepted[] = $username;
            } catch (InvalidArgumentException) {
                // Expected canonical write-boundary rejection.
            }
        }

        $this->assertSame(
            [],
            $unexpectedlyAccepted,
            sprintf(
                'Invalid usernames unexpectedly accepted: %s',
                json_encode(
                    $unexpectedlyAccepted,
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );
    }
}
