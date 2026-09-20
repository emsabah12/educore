<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Authorization\Database\Seeders\AuthorizationCatalogSeeder;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Tests\TestCase;

/**
 * §Pendaftaran tenant mandiri (self-service) — lihat catatan
 * arsitektur lengkap di `TenantSelfRegistrationController`.
 *
 * `AuthorizationCatalogSeeder` WAJIB di-seed di setiap test —
 * `TenantProvisioningService::provisionWithNewAdmin()` (dipakai
 * ulang oleh controller ini) mensyaratkan role admin kanonik SUDAH
 * ada (fail closed lewat `requireAdminRole()` kalau tidak), persis
 * seperti `TenantProvisioningServiceTest` yang sudah ada.
 */
final class TenantSelfRegistrationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'session.driver' => 'array',
        ]);

        $this->app->instance(
            AuditTrailServiceInterface::class,
            $this->createStub(
                AuditTrailServiceInterface::class,
            ),
        );

        $this->seed(
            AuthorizationCatalogSeeder::class,
        );
    }

    public function test_registers_tenant_and_establishes_browser_identity_session_end_to_end(): void
    {
        $beforeSessionId = $this->app[
            'session'
        ]->getId();

        $subdomain = sprintf(
            'sekolah-baru-%s',
            Str::lower(Str::random(8)),
        );

        $email = sprintf(
            'admin-baru-%s@educore.test',
            Str::lower(Str::random(8)),
        );

        $response = $this->postJson(
            '/api/v1/browser/auth/register',
            [
                'name' => 'SMA Negeri Uji Coba',
                'subdomain' => $subdomain,
                'admin_name' => 'Kepala Sekolah Baru',
                'admin_email' => $email,
                'admin_password' => 'secret123',
            ],
        );

        $response->assertCreated();

        $body = $response->json();

        $this->assertSame('success', $body['status']);
        $this->assertSame('identity', $body['data']['context_type']);
        $this->assertSame('Kepala Sekolah Baru', $body['data']['user']['name']);
        $this->assertSame($email, $body['data']['user']['email']);
        $this->assertNull($body['data']['user']['username']);
        $this->assertFalse($body['data']['platform']['is_superadmin']);
        $this->assertSame('SMA Negeri Uji Coba', $body['data']['tenant']['name']);
        $this->assertSame($subdomain, $body['data']['tenant']['subdomain']);

        // §Tidak ada bearer/access_token yang bocor ke JavaScript --
        // sama seperti login biasa, otoritas tetap di sesi server.
        $responseContent = $response->getContent();

        $this->assertStringNotContainsString(
            'access_token',
            $responseContent,
        );

        // §Sesi pra-registrasi WAJIB diregenerasi (cegah session
        // fixation) -- sama persis seperti login biasa.
        $afterSessionId = $this->app[
            'session'
        ]->getId();

        $this->assertNotSame(
            $beforeSessionId,
            $afterSessionId,
            'Tenant registration must regenerate the pre-registration session identifier.',
        );

        // §Tenant benar-benar tercipta AKTIF di database, sesuai
        // keputusan produk -- tanpa gerbang approval manual.
        $this->assertDatabaseHas('tenants', [
            'subdomain' => $subdomain,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('users', [
            'email' => $email,
        ]);

        // §Bukti end-to-end PALING KUAT bahwa auto-login benar-benar
        // berfungsi -- bukan cuma bentuk respons yang superfisial:
        // panggil endpoint discovery Membership canonical MEMAKAI
        // SESI YANG SAMA (tanpa login ulang sama sekali), dan
        // pastikan Membership baru yang baru saja dibuat SUDAH
        // langsung ditemukan.
        // §Perbaikan test — Laravel test client TIDAK secara otomatis
        // membawa cookie sesi dari satu panggilan HTTP ke panggilan
        // berikutnya dalam test yang sama (berbeda dari browser
        // sungguhan). Perlu menyuntikkan cookie sesi secara eksplisit
        // supaya panggilan BERIKUTNYA benar-benar "melanjutkan" sesi
        // yang sama -- pola identik dengan
        // CanonicalBrowserMembershipDiscoveryTest::loginBrowserIdentityAndAttachCookie().
        $this
            ->withCredentials()
            ->withCookie(
                $this->sessionCookieName(),
                $this->app[
                    'session'
                ]->getId(),
            );

        $membershipsResponse = $this->getJson(
            '/api/v1/user/my-memberships',
        );

        $membershipsResponse->assertOk();

        $memberships = $membershipsResponse->json('data');

        $this->assertCount(
            1,
            $memberships,
            'The freshly registered admin must immediately discover exactly one Membership -- their own new Tenant -- through the same Browser session, without logging in again.',
        );

        $registeredTenantId = DB::table('tenants')
            ->where('subdomain', $subdomain)
            ->value('id');

        $this->assertSame(
            $registeredTenantId,
            $memberships[0]['tenant_id']
                ?? null,
        );
    }

    public function test_admin_role_is_assigned_to_the_new_membership(): void
    {
        $subdomain = sprintf(
            'cek-role-%s',
            Str::lower(Str::random(8)),
        );

        $this->postJson(
            '/api/v1/browser/auth/register',
            [
                'name' => 'Yayasan Uji Peran',
                'subdomain' => $subdomain,
                'admin_name' => 'Admin Uji Peran',
                'admin_email' => sprintf('cek-role-%s@educore.test', Str::lower(Str::random(8))),
                'admin_password' => 'secret123',
            ],
        )->assertCreated();

        $tenantId = DB::table('tenants')
            ->where('subdomain', $subdomain)
            ->value('id');

        $membershipId = DB::table('memberships')
            ->where('tenant_id', $tenantId)
            ->value('id');

        $adminRoleId = DB::table('roles')
            ->whereNull('tenant_id')
            ->where('name', 'admin')
            ->value('id');

        $this->assertDatabaseHas('membership_roles', [
            'membership_id' => $membershipId,
            'role_id' => $adminRoleId,
        ]);
    }

    public function test_rejects_duplicate_subdomain(): void
    {
        $subdomain = sprintf(
            'duplikat-%s',
            Str::lower(Str::random(8)),
        );

        $this->postJson(
            '/api/v1/browser/auth/register',
            [
                'name' => 'Sekolah Pertama',
                'subdomain' => $subdomain,
                'admin_name' => 'Admin Pertama',
                'admin_email' => sprintf('pertama-%s@educore.test', Str::lower(Str::random(8))),
                'admin_password' => 'secret123',
            ],
        )->assertCreated();

        $response = $this->postJson(
            '/api/v1/browser/auth/register',
            [
                'name' => 'Sekolah Kedua',
                'subdomain' => $subdomain,
                'admin_name' => 'Admin Kedua',
                'admin_email' => sprintf('kedua-%s@educore.test', Str::lower(Str::random(8))),
                'admin_password' => 'secret123',
            ],
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('subdomain');
    }

    public function test_rejects_duplicate_admin_email(): void
    {
        $email = sprintf(
            'sama-%s@educore.test',
            Str::lower(Str::random(8)),
        );

        $this->postJson(
            '/api/v1/browser/auth/register',
            [
                'name' => 'Sekolah A',
                'subdomain' => sprintf('sekolah-a-%s', Str::lower(Str::random(8))),
                'admin_name' => 'Admin A',
                'admin_email' => $email,
                'admin_password' => 'secret123',
            ],
        )->assertCreated();

        $response = $this->postJson(
            '/api/v1/browser/auth/register',
            [
                'name' => 'Sekolah B',
                'subdomain' => sprintf('sekolah-b-%s', Str::lower(Str::random(8))),
                'admin_name' => 'Admin B',
                'admin_email' => $email,
                'admin_password' => 'secret123',
            ],
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('admin_email');
    }

    public function test_validation_rejects_missing_fields(): void
    {
        $response = $this->postJson(
            '/api/v1/browser/auth/register',
            [],
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors([
            'name',
            'subdomain',
            'admin_name',
            'admin_email',
            'admin_password',
        ]);
    }

    public function test_validation_rejects_short_password(): void
    {
        $response = $this->postJson(
            '/api/v1/browser/auth/register',
            [
                'name' => 'Sekolah Password Pendek',
                'subdomain' => sprintf('pw-pendek-%s', Str::lower(Str::random(8))),
                'admin_name' => 'Admin Password Pendek',
                'admin_email' => sprintf('pw-pendek-%s@educore.test', Str::lower(Str::random(8))),
                'admin_password' => 'short',
            ],
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('admin_password');
    }

    public function test_validation_rejects_invalid_subdomain_format(): void
    {
        $response = $this->postJson(
            '/api/v1/browser/auth/register',
            [
                'name' => 'Sekolah Subdomain Salah',
                'subdomain' => 'Subdomain Dengan Spasi!',
                'admin_name' => 'Admin Subdomain Salah',
                'admin_email' => sprintf('subdomain-salah-%s@educore.test', Str::lower(Str::random(8))),
                'admin_password' => 'secret123',
            ],
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('subdomain');
    }

    public function test_registration_fails_closed_when_canonical_admin_role_is_not_seeded(): void
    {
        DB::table('role_permissions')
            ->whereIn(
                'role_id',
                DB::table('roles')->whereNull('tenant_id')->where('name', 'admin')->pluck('id'),
            )
            ->delete();

        DB::table('roles')
            ->whereNull('tenant_id')
            ->where('name', 'admin')
            ->delete();

        $response = $this->postJson(
            '/api/v1/browser/auth/register',
            [
                'name' => 'Sekolah Tanpa Role Admin',
                'subdomain' => sprintf('tanpa-role-%s', Str::lower(Str::random(8))),
                'admin_name' => 'Admin Tanpa Role',
                'admin_email' => sprintf('tanpa-role-%s@educore.test', Str::lower(Str::random(8))),
                'admin_password' => 'secret123',
            ],
        );

        $response->assertStatus(500);
        $response->assertJsonPath('code', 'INTERNAL_SERVER_ERROR');
    }

    public function test_rate_limit_blocks_after_too_many_attempts_from_same_ip(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(
                '/api/v1/browser/auth/register',
                [],
            );
        }

        $response = $this->postJson(
            '/api/v1/browser/auth/register',
            [],
        );

        $response->assertStatus(429);
    }

    private function sessionCookieName(): string
    {
        $cookieName = config('session.cookie');

        $this->assertIsString($cookieName);
        $this->assertNotSame('', trim($cookieName));

        return $cookieName;
    }
}
