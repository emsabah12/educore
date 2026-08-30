<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class GlobalApiLoginContractTest extends TestCase
{
    use RefreshDatabase;

    private string $userId;

    private string $personId;

    private string $email;

    private string $username;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Audit persistence is outside this transport-contract test.
         *
         * Authentication failure/success behavior remains exercised, while
         * the test avoids coupling itself to audit storage infrastructure.
         */
        $this->app->instance(
            AuditTrailServiceInterface::class,
            $this->createStub(
                AuditTrailServiceInterface::class,
            ),
        );

        $this->userId = UuidV7::generate();
        $this->personId = UuidV7::generate();

        $suffix = Str::lower(
            Str::random(10),
        );

        $this->email = sprintf(
            'global-login-%s@educore.test',
            $suffix,
        );

        $this->username = sprintf(
            'global.%s',
            $suffix,
        );

        $this->createGlobalIdentityFixture();
    }

    public function test_email_login_without_membership_returns_identity_credential(): void
    {
        $response = $this->postJson(
            '/api/v1/auth/login-token',
            [
                'identifier' => strtoupper(
                    $this->email,
                ),
                'password' => 'secret123',
            ],
        );

        $this->assertCanonicalIdentityLoginResponse(
            $response,
        );
    }

    public function test_username_login_without_membership_returns_identity_credential(): void
    {
        $response = $this->postJson(
            '/api/v1/auth/login-token',
            [
                'identifier' => strtoupper(
                    $this->username,
                ),
                'password' => 'secret123',
            ],
        );

        $this->assertCanonicalIdentityLoginResponse(
            $response,
        );
    }

    public function test_invalid_password_returns_generic_authentication_failure(): void
    {
        $response = $this->postJson(
            '/api/v1/auth/login-token',
            [
                'identifier' => $this->username,
                'password' => 'wrong-password',
            ],
        );

        $response
            ->assertUnauthorized()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'AUTHENTICATION_FAILED',
                'message' =>
                    'Invalid authentication credentials.',
            ]);

        $this->assertNull(
            $response->json(
                'data.access_token',
            ),
        );
    }

    private function assertCanonicalIdentityLoginResponse(
        mixed $response,
    ): void {
        $response
            ->assertOk()
            ->assertJsonPath(
                'status',
                'success',
            )
            ->assertJsonPath(
                'data.token_type',
                'Bearer',
            )
            ->assertJsonPath(
                'data.context_type',
                'identity',
            )
            ->assertJsonPath(
                'data.user.id',
                $this->userId,
            )
            ->assertJsonPath(
                'data.user.name',
                'Global Login User',
            )
            ->assertJsonPath(
                'data.user.email',
                $this->email,
            )
            ->assertJsonPath(
                'data.user.username',
                $this->username,
            )
            ->assertJsonPath(
                'data.platform.is_superadmin',
                true,
            );

        $accessToken = $response->json(
            'data.access_token',
        );

        $expiresIn = $response->json(
            'data.expires_in',
        );

        $this->assertIsString(
            $accessToken,
        );

        $this->assertNotSame(
            '',
            trim($accessToken),
        );

        $this->assertIsInt(
            $expiresIn,
        );

        $this->assertGreaterThan(
            0,
            $expiresIn,
        );

        $data = $response->json(
            'data',
        );

        $this->assertIsArray(
            $data,
        );

        $this->assertArrayNotHasKey(
            'context',
            $data,
            'Global login response must not expose legacy Membership/Tenant context.',
        );

        $encodedData = json_encode(
            $data,
            JSON_THROW_ON_ERROR,
        );

        $this->assertStringNotContainsString(
            'tenant_id',
            $encodedData,
        );

        $this->assertStringNotContainsString(
            'membership_id',
            $encodedData,
        );

        /*
         * Verify the transport actually received an Identity Credential,
         * rather than merely receiving an identity-looking response around
         * a legacy Membership credential.
         */
        $claims = app(
            TokenManagerInterface::class,
        )->validateAndExtract(
            $accessToken,
        );

        $this->assertIsArray(
            $claims,
        );

        $this->assertSame(
            'identity',
            $claims['credential_type']
                ?? null,
        );

        $this->assertSame(
            $this->userId,
            $claims['user_id']
                ?? null,
        );

        $this->assertArrayHasKey(
            'expires_at',
            $claims,
        );

        $this->assertIsInt(
            $claims['expires_at'],
        );

        $this->assertArrayNotHasKey(
            'tenant_id',
            $claims,
        );

        $this->assertArrayNotHasKey(
            'membership_id',
            $claims,
        );

        /*
         * The fixture intentionally has no Membership. Successful login must
         * therefore prove that global credential verification is independent
         * from Tenant/Membership availability.
         */
        $this->assertDatabaseMissing(
            'memberships',
            [
                'person_id' => $this->personId,
            ],
        );
    }

    private function createGlobalIdentityFixture(): void
    {
        DB::table('persons')->insert([
            'id' => $this->personId,
            'name' => 'Global Login User',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->userId,
            'person_id' => $this->personId,
            'email' => $this->email,
            'username' => $this->username,
            'password' => bcrypt(
                'secret123',
            ),
            'status' => 'ACTIVE',
            'is_superadmin' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
         * Deliberately do not create:
         *
         * - Tenant
         * - Membership
         *
         * Their absence is part of the contract under test.
         */
    }
}
