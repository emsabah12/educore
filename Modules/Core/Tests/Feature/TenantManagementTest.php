<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Authorization\Database\Seeders\AuthorizationCatalogSeeder;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;
use Illuminate\Support\Facades\Log;
use Modules\Core\Tenancy\Contracts\TenantRepositoryInterface;
use RuntimeException;


final class TenantManagementTest extends TestCase
{
    use RefreshDatabase;

    private const TENANTS_ENDPOINT = '/api/v1/core/tenants';

    private string $superadminId;
    private string $superadminPersonId;
    private string $pegawaiId;
    private string $pegawaiPersonId;
    private string $tenantId;

    private string $superadminMembershipId;
    private string $pegawaiMembershipId;

    private TokenManagerInterface $tokenManager;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Audit persistence bukan fokus test ini.
         *
         * Test tetap menguji route, authentication middleware,
         * global authorization middleware, controller, repository,
         * dan database tenant.
         */
        $this->app->instance(
            AuditTrailServiceInterface::class,
            $this->createStub(
                AuditTrailServiceInterface::class,
            ),
        );

        $this->tokenManager = $this->app->make(
            TokenManagerInterface::class,
        );

        $this->superadminId = UuidV7::generate();
        $this->superadminPersonId = UuidV7::generate();
        $this->pegawaiId = UuidV7::generate();
        $this->pegawaiPersonId = UuidV7::generate();
        $this->tenantId = UuidV7::generate();

        $this->superadminMembershipId = UuidV7::generate();
        $this->pegawaiMembershipId = UuidV7::generate();

        $this->createFixtures();

        $this->seed(
            AuthorizationCatalogSeeder::class,
        );
    }

    public function test_repository_failure_returns_generic_error_and_is_logged(): void
    {
        Log::spy();

        $repository = $this->createMock(
            TenantRepositoryInterface::class,
        );

        $repository
            ->expects($this->once())
            ->method('create')
            ->willThrowException(
                new RuntimeException(
                    'Database connection password=internal-secret',
                ),
            );

        $this->app->instance(
            TenantRepositoryInterface::class,
            $repository,
        );

        $response = $this
            ->withToken(
                $this->issueToken(
                    $this->superadminId,
                    $this->superadminMembershipId,
                ),
            )
            ->postJson(
                self::TENANTS_ENDPOINT,
                [
                    'name' => 'Tenant Repository Gagal',
                    'subdomain' => 'repository-gagal',
                    'initial_admin_user_id' => $this->pegawaiId,
                ],
            );

        $response
            ->assertInternalServerError()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'INTERNAL_SERVER_ERROR',
                'message' => 'Failed to register tenant.',
            ])
            ->assertDontSee('internal-secret');

        $this->assertDatabaseMissing('tenants', [
            'subdomain' => 'repository-gagal',
        ]);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(
                function (
                    string $message,
                    array $context,
                ): bool {
                    return $message
                        === 'Tenant management operation failed.'
                        && $context['operation']
                        === 'tenant.create'
                        && $context['operator_id']
                        === $this->superadminId
                        && $context['tenant_id'] === null
                        && $context['exception']
                        instanceof RuntimeException;
                },
            );
    }

    public function test_audit_failure_does_not_change_success_response(): void
    {
        Log::spy();

        $auditTrail = $this->createMock(
            AuditTrailServiceInterface::class,
        );

        $auditTrail
            ->expects($this->once())
            ->method('log')
            ->willThrowException(
                new RuntimeException(
                    'Audit storage unavailable.',
                ),
            );

        $this->app->instance(
            AuditTrailServiceInterface::class,
            $auditTrail,
        );

        $payload = [
            'name' => 'Tenant Audit Gagal',
            'subdomain' => 'audit-gagal',
            'initial_admin_user_id' => $this->pegawaiId,
        ];

        $response = $this
            ->withToken(
                $this->issueToken(
                    $this->superadminId,
                    $this->superadminMembershipId,
                ),
            )
            ->postJson(
                self::TENANTS_ENDPOINT,
                $payload,
            );

        $response
            ->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath(
                'data.subdomain',
                $payload['subdomain'],
            );

        $this->assertDatabaseHas('tenants', [
            'name' => $payload['name'],
            'subdomain' => $payload['subdomain'],
            'is_active' => true,
        ]);

        Log::shouldHaveReceived('critical')
            ->once()
            ->withArgs(
                function (
                    string $message,
                    array $context,
                ): bool {
                    return $message
                        === 'Tenant audit trail failed.'
                        && $context['event_type']
                        === 'tenant.created'
                        && $context['operator_id']
                        === $this->superadminId
                        && $context['exception']
                        instanceof RuntimeException;
                },
            );
    }

    public function test_global_superadmin_can_create_new_tenant(): void
    {
        $payload = [
            'name' => 'SMP IT Inovasi Bangsa',
            'subdomain' => 'smp-inovasi',
            'initial_admin_user_id' => $this->pegawaiId,
        ];

        $response = $this
            ->withToken(
                $this->issueToken(
                    $this->superadminId,
                    $this->superadminMembershipId,
                ),
            )
            ->postJson(
                self::TENANTS_ENDPOINT,
                $payload,
            );

        $response
            ->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath(
                'message',
                'Tenant registered successfully.',
            )
            ->assertJsonPath(
                'data.name',
                $payload['name'],
            )
            ->assertJsonPath(
                'data.subdomain',
                $payload['subdomain'],
            );

        $this->assertDatabaseHas('tenants', [
            'name' => $payload['name'],
            'subdomain' => $payload['subdomain'],
            'is_active' => true,
        ]);

        $tenantId = (string) DB::table('tenants')
            ->where('subdomain', $payload['subdomain'])
            ->value('id');

        $membershipId = (string) DB::table('memberships')
            ->where('person_id', $this->pegawaiPersonId)
            ->where('tenant_id', $tenantId)
            ->value('id');

        $adminRoleId = (string) DB::table('roles')
            ->where('name', 'admin')
            ->value('id');

        $response
            ->assertJsonPath(
                'data.initial_admin.user_id',
                $this->pegawaiId,
            )
            ->assertJsonPath(
                'data.initial_admin.membership_id',
                $membershipId,
            );

        $this->assertDatabaseHas('membership_roles', [
            'membership_id' => $membershipId,
            'role_id' => $adminRoleId,
        ]);
    }

    public function test_store_requires_initial_admin_user_id(): void
    {
        $this
            ->withToken(
                $this->issueToken(
                    $this->superadminId,
                    $this->superadminMembershipId,
                ),
            )
            ->postJson(
                self::TENANTS_ENDPOINT,
                [
                    'name' => 'Tenant Tanpa Admin',
                    'subdomain' => 'tanpa-admin',
                ],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'initial_admin_user_id',
            ]);

        $this->assertDatabaseMissing('tenants', [
            'subdomain' => 'tanpa-admin',
        ]);
    }

    public function test_store_rejects_non_uuid_v7_initial_admin_user_id(): void
    {
        $this
            ->withToken(
                $this->issueToken(
                    $this->superadminId,
                    $this->superadminMembershipId,
                ),
            )
            ->postJson(
                self::TENANTS_ENDPOINT,
                [
                    'name' => 'Tenant Invalid Admin UUID',
                    'subdomain' => 'invalid-admin-uuid',
                    'initial_admin_user_id' => (string) Str::uuid(),
                ],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'initial_admin_user_id',
            ])
            ->assertJsonPath(
                'errors.initial_admin_user_id.0',
                'The initial admin user id must be a valid UUIDv7.',
            );

        $this->assertDatabaseMissing('tenants', [
            'subdomain' => 'invalid-admin-uuid',
        ]);
    }

    public function test_store_rejects_user_with_inactive_person(): void
    {
        DB::table('persons')
            ->where('id', $this->pegawaiPersonId)
            ->update([
                'status' => 'INACTIVE',
                'updated_at' => now(),
            ]);

        $response = $this
            ->withToken(
                $this->issueToken(
                    $this->superadminId,
                    $this->superadminMembershipId,
                ),
            )
            ->postJson(
                self::TENANTS_ENDPOINT,
                [
                    'name' =>
                    'Tenant Inactive Person',
                    'subdomain' =>
                    'inactive-person-http',
                    'initial_admin_user_id' =>
                    $this->pegawaiId,
                ],
            );

        $response
            ->assertUnprocessable()
            ->assertJsonPath(
                'status',
                'error',
            )
            ->assertJsonPath(
                'code',
                'VALIDATION_FAILED',
            )
            ->assertJsonPath(
                'message',
                'The submitted data is invalid.',
            )
            ->assertJsonValidationErrors([
                'initial_admin_user_id',
            ])
            ->assertJsonPath(
                'errors.initial_admin_user_id.0',
                'The selected initial admin user is not eligible.',
            );

        $this->assertDatabaseMissing('tenants', [
            'subdomain' => 'inactive-person-http',
        ]);
    }

    public function test_non_superadmin_is_forbidden_to_create_tenant(): void
    {
        $payload = [
            'name' => 'Lembaga Penerobos',
            'subdomain' => 'terobos',
            'initial_admin_user_id' => $this->pegawaiId,
        ];

        $response = $this
            ->withToken(
                $this->issueToken(
                    $this->pegawaiId,
                    $this->pegawaiMembershipId,
                ),
            )
            ->postJson(
                self::TENANTS_ENDPOINT,
                $payload,
            );

        $response
            ->assertForbidden()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'AUTHORIZATION_DENIED',
                'message' =>
                'You are not allowed to perform this operation.',
            ]);

        $this->assertDatabaseMissing('tenants', [
            'subdomain' => $payload['subdomain'],
        ]);
    }

    public function test_tenant_management_requires_authenticated_identity(): void
    {
        $this
            ->postJson(
                self::TENANTS_ENDPOINT,
                [
                    'name' => 'Tenant Tanpa Authentication',
                    'subdomain' => 'tanpa-auth',
                    'initial_admin_user_id' => $this->pegawaiId,
                ],
            )
            ->assertUnauthorized()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'AUTHENTICATION_REQUIRED',
                'message' =>
                'Unauthenticated. Invalid or missing identity context.',
            ]);

        $this->assertDatabaseMissing('tenants', [
            'subdomain' => 'tanpa-auth',
        ]);
    }

    public function test_update_rejects_malformed_tenant_id(): void
    {
        $response = $this
            ->withToken(
                $this->issueToken(
                    $this->superadminId,
                    $this->superadminMembershipId,
                ),
            )
            ->putJson(
                self::TENANTS_ENDPOINT . '/not-a-uuid',
                [
                    'name' => 'Nama Tenant Baru',
                ],
            );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'id',
            ])
            ->assertJsonPath(
                'errors.id.0',
                'The tenant id must be a valid UUIDv7.',
            );
    }

    public function test_update_rejects_uuid_other_than_version_seven(): void
    {
        $uuidV4 = (string) Str::uuid();

        $response = $this
            ->withToken(
                $this->issueToken(
                    $this->superadminId,
                    $this->superadminMembershipId,
                ),
            )
            ->putJson(
                self::TENANTS_ENDPOINT . '/' . $uuidV4,
                [
                    'name' => 'Nama Tenant Baru',
                ],
            );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'id',
            ]);

        $this->assertDatabaseMissing('tenants', [
            'id' => $uuidV4,
        ]);
    }

    public function test_update_rejects_empty_payload(): void
    {
        $beforeUpdate = DB::table('tenants')
            ->where('id', $this->tenantId)
            ->value('updated_at');

        $response = $this
            ->withToken(
                $this->issueToken(
                    $this->superadminId,
                    $this->superadminMembershipId,
                ),
            )
            ->putJson(
                self::TENANTS_ENDPOINT . '/' . $this->tenantId,
                [],
            );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'payload',
            ])
            ->assertJsonPath(
                'errors.payload.0',
                'At least one of name or is_active must be provided.',
            );

        $afterUpdate = DB::table('tenants')
            ->where('id', $this->tenantId)
            ->value('updated_at');

        $this->assertEquals(
            $beforeUpdate,
            $afterUpdate,
        );
    }

    public function test_global_superadmin_can_deactivate_tenant(): void
    {
        $response = $this
            ->withToken(
                $this->issueToken(
                    $this->superadminId,
                    $this->superadminMembershipId,
                ),
            )
            ->putJson(
                self::TENANTS_ENDPOINT . '/' . $this->tenantId,
                [
                    'is_active' => false,
                ],
            );

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath(
                'message',
                'Tenant updated successfully.',
            )
            ->assertJsonPath(
                'data.id',
                $this->tenantId,
            )
            ->assertJsonPath(
                'data.is_active',
                false,
            );

        $this->assertDatabaseHas('tenants', [
            'id' => $this->tenantId,
            'is_active' => false,
        ]);
    }

    private function createFixtures(): void
    {
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Sekolah Pusat EduCore',
            'subdomain' => 'pusat',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('persons')->insert([
            [
                'id' => $this->superadminPersonId,
                'name' => 'Superadmin Global',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->pegawaiPersonId,
                'name' => 'Pegawai Administrasi',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('users')->insert([
            [
                'id' => $this->superadminId,
                'person_id' => $this->superadminPersonId,
                'email' => 'super@educore.test',
                'password' => Hash::make('secret123'),
                'status' => 'ACTIVE',
                'is_superadmin' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->pegawaiId,
                'person_id' => $this->pegawaiPersonId,
                'email' => 'staff@educore.test',
                'password' => Hash::make('secret123'),
                'status' => 'ACTIVE',
                'is_superadmin' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        /*
         * Membership context tetap canonical Person-owned. Global tenant
         * management authorization berasal dari users.is_superadmin dan
         * tidak bergantung pada tenant role.
         */
        DB::table('memberships')->insert([
            [
                'id' => $this->superadminMembershipId,
                'person_id' => $this->superadminPersonId,
                'tenant_id' => $this->tenantId,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->pegawaiMembershipId,
                'person_id' => $this->pegawaiPersonId,
                'tenant_id' => $this->tenantId,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    private function issueToken(
        string $userId,
        string $membershipId,
    ): string {
        return $this->tokenManager->issueToken(
            $userId,
            $this->tenantId,
            [
                'membership_id' => $membershipId,
            ],
        );
    }

    public function test_global_superadmin_does_not_require_active_tenant_context(): void
    {
        $unresolvedTenantId = UuidV7::generate();

        $payload = [
            'name' => 'Tenant Global Tanpa Context',
            'subdomain' => 'global-tanpa-context',
            'initial_admin_user_id' => $this->pegawaiId,
        ];

        /*
     * Token sengaja menunjuk ke tenant yang tidak ada.
     *
     * Global tenant management hanya membutuhkan canonical identity
     * dan users.is_superadmin. Tenant context tidak boleh di-resolve.
     */
        $token = $this->tokenManager->issueToken(
            $this->superadminId,
            $unresolvedTenantId,
            [
                'membership_id' => $this->superadminMembershipId,
            ],
        );

        $response = $this
            ->withToken($token)
            ->postJson(
                self::TENANTS_ENDPOINT,
                $payload,
            );

        $response
            ->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath(
                'data.subdomain',
                $payload['subdomain'],
            );

        $this->assertDatabaseHas('tenants', [
            'name' => $payload['name'],
            'subdomain' => $payload['subdomain'],
            'is_active' => true,
        ]);
    }

    public function test_index_rejects_invalid_per_page_value(): void
    {
        $response = $this
            ->withToken(
                $this->issueToken(
                    $this->superadminId,
                    $this->superadminMembershipId,
                ),
            )
            ->getJson(
                self::TENANTS_ENDPOINT . '?per_page=101',
            );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'per_page',
            ])
            ->assertJsonPath(
                'errors.per_page.0',
                'The per page value may not exceed 100.',
            );
    }

    public function test_global_superadmin_can_list_tenants_with_requested_page_size(): void
    {
        DB::table('tenants')->insert([
            [
                'id' => UuidV7::generate(),
                'name' => 'Tenant Pagination A',
                'subdomain' => 'pagination-a',
                'is_active' => true,
                'created_at' => now()->subMinute(),
                'updated_at' => now()->subMinute(),
            ],
            [
                'id' => UuidV7::generate(),
                'name' => 'Tenant Pagination B',
                'subdomain' => 'pagination-b',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this
            ->withToken(
                $this->issueToken(
                    $this->superadminId,
                    $this->superadminMembershipId,
                ),
            )
            ->getJson(
                self::TENANTS_ENDPOINT . '?per_page=2',
            );

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonCount(2, 'data');
    }

    public function test_store_normalizes_name_and_subdomain_before_persistence(): void
    {
        $response = $this
            ->withToken(
                $this->issueToken(
                    $this->superadminId,
                    $this->superadminMembershipId,
                ),
            )
            ->postJson(
                self::TENANTS_ENDPOINT,
                [
                    'name' => '  Sekolah Normalisasi  ',
                    'subdomain' => '  SEKOLAH-NORMALISASI  ',
                    'initial_admin_user_id' => '  ' . $this->pegawaiId . '  ',
                ],
            );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.name',
                'Sekolah Normalisasi',
            )
            ->assertJsonPath(
                'data.subdomain',
                'sekolah-normalisasi',
            );

        $this->assertDatabaseHas('tenants', [
            'name' => 'Sekolah Normalisasi',
            'subdomain' => 'sekolah-normalisasi',
        ]);
    }

    public function test_store_rejects_duplicate_subdomain_after_normalization(): void
    {
        $response = $this
            ->withToken(
                $this->issueToken(
                    $this->superadminId,
                    $this->superadminMembershipId,
                ),
            )
            ->postJson(
                self::TENANTS_ENDPOINT,
                [
                    'name' => 'Tenant Duplikat',
                    'subdomain' => '  PUSAT  ',
                    'initial_admin_user_id' => $this->pegawaiId,
                ],
            );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'subdomain',
            ])
            ->assertJsonPath(
                'errors.subdomain.0',
                'The tenant subdomain has already been registered.',
            );

        $this->assertSame(
            1,
            DB::table('tenants')
                ->where('subdomain', 'pusat')
                ->count(),
        );
    }

    public function test_update_returns_canonical_not_found_error_for_unknown_tenant(): void
    {
        $unknownTenantId =
            UuidV7::generate();

        $response = $this
            ->withToken(
                $this->issueToken(
                    $this->superadminId,
                    $this->superadminMembershipId,
                ),
            )
            ->putJson(
                self::TENANTS_ENDPOINT
                    . '/'
                    . $unknownTenantId,
                [
                    'name' =>
                    'Unknown Tenant Update',
                ],
            );

        $response
            ->assertNotFound()
            ->assertExactJson([
                'status' => 'error',
                'code' => 'RESOURCE_NOT_FOUND',
                'message' => 'Tenant not found.',
            ]);

        $this->assertDatabaseMissing(
            'tenants',
            [
                'id' => $unknownTenantId,
            ],
        );
    }
}
