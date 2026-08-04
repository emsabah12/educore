<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Auth\Http\Middleware\InjectAuthenticatedUser;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Tests\TestCase;

final class InjectAuthenticatedUserTest extends TestCase
{
    use RefreshDatabase;

    private string $activeUserId;
    private string $suspendedUserId;
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activeUserId = Str::uuid()->toString();
        $this->suspendedUserId = Str::uuid()->toString();
        $this->tenantId = Str::uuid()->toString();

        $this->createUsers();
        $this->registerTestRoute();
    }

    public function test_valid_token_injects_canonical_authenticated_user(): void
    {
        $token = $this->issueToken(
            $this->activeUserId,
        );

        $response = $this
            ->withToken($token)
            ->getJson('/test-auth/identity');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath(
                'user_id',
                $this->activeUserId,
            )
            ->assertJsonPath(
                'authenticated_user_id',
                $this->activeUserId,
            )
            ->assertJsonMissingPath(
                'authenticated_tenant_id',
            );
    }

    public function test_request_without_bearer_token_is_rejected(): void
    {
        $this
            ->getJson('/test-auth/identity')
            ->assertUnauthorized()
            ->assertJsonPath('status', 'error');
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this
            ->withToken('invalid-token')
            ->getJson('/test-auth/identity')
            ->assertUnauthorized();
    }

    public function test_token_without_user_id_is_rejected(): void
    {
        $tokenManager = $this->createMock(
            TokenManagerInterface::class,
        );

        $tokenManager
            ->method('validateAndExtract')
            ->willReturn([
                'tenant_id' => $this->tenantId,
                'expires_at' => time() + 3600,
            ]);

        $this->app->instance(
            TokenManagerInterface::class,
            $tokenManager,
        );

        $this
            ->withToken('token-without-user')
            ->getJson('/test-auth/identity')
            ->assertUnauthorized();
    }

    public function test_unknown_user_is_rejected(): void
    {
        $token = $this->issueToken(
            Str::uuid()->toString(),
        );

        $this
            ->withToken($token)
            ->getJson('/test-auth/identity')
            ->assertUnauthorized();
    }

    public function test_suspended_user_is_rejected(): void
    {
        $token = $this->issueToken(
            $this->suspendedUserId,
        );

        $this
            ->withToken($token)
            ->getJson('/test-auth/identity')
            ->assertUnauthorized();
    }

    public function test_tenant_context_is_not_injected(): void
    {
        $token = $this->issueToken(
            $this->activeUserId,
        );

        $response = $this
            ->withToken($token)
            ->getJson('/test-auth/identity');

        $response
            ->assertOk()
            ->assertJsonPath(
                'has_authenticated_tenant_id',
                false,
            );
    }

    private function registerTestRoute(): void
    {
        Route::middleware([
            InjectAuthenticatedUser::class,
        ])->get(
            '/test-auth/identity',
            static function (Request $request): array {
                $user = $request->user();

                return [
                    'status' => 'success',
                    'user_id' => $user !== null
                        ? (string) $user->getAuthIdentifier()
                        : null,
                    'authenticated_user_id' => $request->attributes->get(
                        'authenticated_user_id',
                    ),
                    'has_authenticated_tenant_id' => $request->attributes->has(
                        'authenticated_tenant_id',
                    ),
                ];
            },
        );
    }

    private function issueToken(
        string $userId,
    ): string {
        return app(TokenManagerInterface::class)
            ->issueToken(
                $userId,
                $this->tenantId,
            );
    }

    private function createUsers(): void
    {
        DB::table('users')->insert([
            [
                'id' => $this->activeUserId,
                'name' => 'Active Identity User',
                'email' => sprintf(
                    'active-identity-%s@educore.test',
                    Str::lower(Str::random(8)),
                ),
                'password' => bcrypt('secret123'),
                'status' => 'ACTIVE',
                'is_superadmin' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->suspendedUserId,
                'name' => 'Suspended Identity User',
                'email' => sprintf(
                    'suspended-identity-%s@educore.test',
                    Str::lower(Str::random(8)),
                ),
                'password' => bcrypt('secret123'),
                'status' => 'SUSPENDED',
                'is_superadmin' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function test_malformed_user_id_claim_is_rejected(): void
    {
        $tokenManager = $this->createMock(
            TokenManagerInterface::class,
        );

        $tokenManager
            ->expects($this->once())
            ->method('validateAndExtract')
            ->with('malformed-user-id-token')
            ->willReturn([
                'user_id' => 'user-uuid-123',
                'tenant_id' => $this->tenantId,
                'expires_at' => time() + 3600,
            ]);

        $this->app->instance(
            TokenManagerInterface::class,
            $tokenManager,
        );

        $this
            ->withToken('malformed-user-id-token')
            ->getJson('/test-auth/identity')
            ->assertUnauthorized()
            ->assertJsonPath('status', 'error');
    }
}
