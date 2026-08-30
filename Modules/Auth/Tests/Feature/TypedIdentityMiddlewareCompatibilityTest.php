<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Middleware\InjectAuthenticatedUser;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Identity\Models\User;
use Modules\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class TypedIdentityMiddlewareCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Membership $membership;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createCanonicalFixture();
        $this->registerTestRoute();
    }

    protected function tearDown(): void
    {
        auth()->forgetGuards();

        parent::tearDown();
    }

    public function test_identity_credential_establishes_user_only_identity_context(): void
    {
        $token = app(TokenManagerInterface::class)
            ->issueIdentityToken(
                (string) $this->user->getKey(),
            );

        $response = $this
            ->withToken($token)
            ->getJson('/test-auth/typed-identity-context');

        $response
            ->assertOk()
            ->assertJsonPath(
                'user_id',
                (string) $this->user->getKey(),
            )
            ->assertJsonPath(
                'authenticated_user_id',
                (string) $this->user->getKey(),
            )
            ->assertJsonPath(
                'has_authenticated_tenant_id',
                false,
            )
            ->assertJsonPath(
                'has_authenticated_membership_id',
                false,
            );

        $this->assertNull(
            auth()->user(),
            'Request-local authenticated User must be cleared after middleware execution.',
        );
    }

    public function test_membership_credential_also_establishes_user_only_identity_context(): void
    {
        $token = app(TokenManagerInterface::class)
            ->issueMembershipToken(
                (string) $this->user->getKey(),
                (string) $this->tenant->getKey(),
                (string) $this->membership->getKey(),
            );

        $response = $this
            ->withToken($token)
            ->getJson('/test-auth/typed-identity-context');

        $response
            ->assertOk()
            ->assertJsonPath(
                'user_id',
                (string) $this->user->getKey(),
            )
            ->assertJsonPath(
                'authenticated_user_id',
                (string) $this->user->getKey(),
            )
            ->assertJsonPath(
                'has_authenticated_tenant_id',
                false,
            )
            ->assertJsonPath(
                'has_authenticated_membership_id',
                false,
            );

        $this->assertNull(
            auth()->user(),
            'Membership Credential must not persist authentication beyond the request lifecycle.',
        );
    }

    private function createCanonicalFixture(): void
    {
        $this->user = User::factory()->create([
            'status' => 'ACTIVE',
        ]);

        $this->tenant = Tenant::query()->create([
            'name' => 'Typed Identity Middleware Tenant',
            'subdomain' => 'typed-identity-' . strtolower(
                fake()->lexify('????????'),
            ),
            'is_active' => true,
        ]);

        $this->membership = Membership::query()->create([
            'person_id' => $this->user->person_id,
            'tenant_id' => $this->tenant->getKey(),
            'status' => 'ACTIVE',
        ]);
    }

    private function registerTestRoute(): void
    {
        Route::middleware([
            InjectAuthenticatedUser::class,
        ])->get(
            '/test-auth/typed-identity-context',
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
                    'has_authenticated_membership_id' => $request->attributes->has(
                        'authenticated_membership_id',
                    ),
                ];
            },
        );
    }
}
