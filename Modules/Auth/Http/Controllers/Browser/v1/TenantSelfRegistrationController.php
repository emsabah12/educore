<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Controllers\Browser\v1;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Auth\BrowserSession\Contracts\BrowserSessionCredentialVaultInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\Core\Tenancy\Http\Requests\RegisterTenantRequest;
use Modules\Core\Tenancy\Services\TenantProvisioningService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * §Pendaftaran tenant mandiri (self-service). Endpoint PUBLIK -- tidak
 * ada middleware autentikasi apa pun, dibatasi hanya oleh rate-limit
 * di level route (lihat `Modules/Auth/Routes/browser.php`).
 *
 * Orkestrasi dua boundary yang SUDAH ADA dan SUDAH teruji, TANPA
 * menduplikasi logikanya:
 *
 * 1. `TenantProvisioningService::provisionWithNewAdmin()` -- SATU-
 *    SATUNYA tempat yang benar-benar membuat Tenant+Person+User+
 *    Membership+role admin+Organization default secara atomik. Method
 *    ini SAMA PERSIS yang dipakai jalur superadmin
 *    (`TenantManagementController::storeWithNewAdmin()`), cuma
 *    dipanggil dari jalur PUBLIK di sini.
 *
 * 2. `BrowserSessionCredentialVaultInterface::establishFreshIdentity()`
 *    -- MEKANISME PERSIS SAMA yang dipakai `BrowserLoginController`
 *    setelah verifikasi kredensial berhasil. TIDAK ADA jalur
 *    "auto-login" terpisah yang baru -- "berhasil daftar" di sini
 *    secara harfiah berarti "identity browser session baru saja
 *    ditetapkan", sama seperti "berhasil login".
 *
 * Tenant hasil pendaftaran mandiri SELALU langsung aktif (keputusan
 * produk -- lihat `RegisterTenantRequest`, tidak ada field
 * `is_active`) -- tidak ada gerbang approval manual.
 */
final readonly class TenantSelfRegistrationController
{
    public function __construct(
        private TenantProvisioningService $tenantProvisioningService,
        private BrowserSessionCredentialVaultInterface $credentialVault,
        private AuditTrailServiceInterface $auditTrail,
    ) {}

    public function __invoke(
        RegisterTenantRequest $request,
    ): JsonResponse {
        /**
         * @var array{
         *     name: string,
         *     subdomain: string,
         *     admin_name: string,
         *     admin_email: string,
         *     admin_password: string,
         * } $validated
         */
        $validated = $request->validated();

        $tenantData = [
            'name' => $validated['name'],
            'subdomain' => $validated['subdomain'],
            'is_active' => true,
        ];

        $adminData = [
            'name' => $validated['admin_name'],
            'email' => $validated['admin_email'],
            'password' => $validated['admin_password'],
        ];

        try {
            $result = $this->tenantProvisioningService->provisionWithNewAdmin(
                $tenantData,
                $adminData,
            );
        } catch (Throwable $exception) {
            Log::error(
                'Self-service tenant registration failed during provisioning.',
                [
                    'exception' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'INTERNAL_SERVER_ERROR',
                message: 'Failed to register your school/institution. Please try again.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        $tenant = $result['tenant'];
        $initialAdmin = $result['initial_admin'];

        try {
            // §Sama persis dengan BrowserLoginController -- cegah
            // session fixation sebelum identity browser baru
            // ditetapkan.
            $request->session()->regenerate(
                true,
            );

            $this->credentialVault
                ->establishFreshIdentity(
                    $initialAdmin['user_id'],
                );
        } catch (Throwable $exception) {
            $this->clearCredentialVaultAfterFailedSessionEstablishment();

            Log::error(
                'Browser session establishment failed after successful tenant registration.',
                [
                    'user_id' => $initialAdmin['user_id'],
                    'tenant_id' => $tenant['id'],
                    'exception' => $exception::class,
                ],
            );

            $this->auditTrail->log(
                'tenant.self_registered_session_failed',
                sprintf(
                    'Pendaftaran tenant mandiri berhasil (%s), tapi gagal membangun sesi browser.',
                    $tenant['name'],
                ),
                (string) $tenant['id'],
                $initialAdmin['user_id'],
                [
                    'channel' => 'browser_session',
                    'status' => 'session_establishment_failed',
                ],
            );

            // Tenant SUDAH terbentuk (transaksi provisioning sudah
            // commit) -- gagal cuma di sesi browser, bukan gagal
            // registrasi. Beri tahu pengguna secara jujur supaya
            // mereka login manual, bukan berpura-pura registrasi itu
            // sendiri gagal.
            return ApiErrorResponse::make(
                code: 'BROWSER_SESSION_UNAVAILABLE',
                message: 'Your school was registered successfully, but we could not sign you in automatically. Please log in.',
                status: Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        $this->auditTrail->log(
            'tenant.self_registered',
            sprintf(
                'Tenant baru didaftarkan secara mandiri: %s (%s)',
                $tenant['name'],
                $tenant['subdomain'],
            ),
            (string) $tenant['id'],
            $initialAdmin['user_id'],
            [
                'channel' => 'browser_session',
                'subdomain' => $tenant['subdomain'],
                'initial_admin_membership_id' => $initialAdmin['membership_id'],
            ],
        );

        return response()->json(
            [
                'status' => 'success',
                'data' => [
                    'context_type' => 'identity',
                    'user' => [
                        'id' => $initialAdmin['user_id'],
                        'name' => $adminData['name'],
                        'email' => $adminData['email'],
                        'username' => null,
                    ],
                    'platform' => [
                        'is_superadmin' => false,
                    ],
                    'tenant' => [
                        'id' => $tenant['id'],
                        'name' => $tenant['name'],
                        'subdomain' => $tenant['subdomain'],
                    ],
                ],
            ],
            Response::HTTP_CREATED,
        );
    }

    private function clearCredentialVaultAfterFailedSessionEstablishment(): void
    {
        try {
            $this->credentialVault->clear();
        } catch (Throwable $cleanupException) {
            Log::critical(
                'Browser credential vault cleanup failed after tenant registration session failure.',
                [
                    'exception' => $cleanupException::class,
                ],
            );
        }
    }
}
