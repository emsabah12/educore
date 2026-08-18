<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Controllers\Browser\v1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Auth\BrowserSession\Contracts\BrowserSessionCredentialInventoryInterface;
use Modules\Auth\BrowserSession\Contracts\BrowserSessionCredentialVaultInterface;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Auth\Token\Contracts\TokenRevocationStoreInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class BrowserLogoutController
{
    public function __construct(
        private BrowserSessionCredentialVaultInterface $credentialVault,
        private BrowserSessionCredentialInventoryInterface $credentialInventory,
        private TokenManagerInterface $tokenManager,
        private TokenRevocationStoreInterface $tokenRevocationStore,
        private AuditTrailServiceInterface $auditTrail,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $userId = $this->credentialVault->userId();
        $credentials = $this->credentialInventory
            ->credentialsForRevocation();

        $revocationFailures = 0;

        foreach ($credentials as $membershipId => $credential) {
            $expiresAt = $this->tokenManager
                ->expiresAtForRevocation($credential);

            if ($expiresAt === null) {
                $revocationFailures++;

                Log::warning(
                    'Browser logout could not resolve credential revocation metadata.',
                    [
                        'user_id' => $userId,
                        'membership_id' => $membershipId,
                    ],
                );

                continue;
            }

            try {
                $this->tokenRevocationStore->revoke(
                    token: $credential,
                    expiresAt: $expiresAt,
                );
            } catch (Throwable $exception) {
                $revocationFailures++;

                Log::error(
                    'Browser logout credential revocation failed.',
                    [
                        'user_id' => $userId,
                        'membership_id' => $membershipId,
                        'exception' => $exception::class,
                    ],
                );
            }
        }

        $cleanupFailed = false;

        try {
            $this->credentialVault->clear();
        } catch (Throwable $exception) {
            $cleanupFailed = true;

            Log::critical(
                'Browser credential vault cleanup failed during logout.',
                [
                    'user_id' => $userId,
                    'exception' => $exception::class,
                ],
            );
        }

        try {
            /*
             * Logout terminates the shared Browser Session Broker session.
             * The new anonymous session gets a fresh CSRF token so a previous
             * authenticated session identifier cannot survive logout.
             */
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        } catch (Throwable $exception) {
            $cleanupFailed = true;

            Log::critical(
                'Browser session invalidation failed during logout.',
                [
                    'user_id' => $userId,
                    'exception' => $exception::class,
                ],
            );
        }

        if ($revocationFailures > 0 || $cleanupFailed) {
            $this->auditFailure(
                userId: $userId,
                credentialCount: count($credentials),
                revocationFailures: $revocationFailures,
                cleanupFailed: $cleanupFailed,
            );

            return ApiErrorResponse::make(
                code: 'LOGOUT_UNAVAILABLE',
                message: 'Unable to complete logout securely.',
                status: Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        if ($userId !== null) {
            $this->auditTrail->log(
                'auth.logout_browser',
                'Browser Session berhasil diakhiri.',
                null,
                $userId,
                [
                    'status' => 'explicit_browser_logout',
                    'credentials_revoked' => count($credentials),
                    'browser_session_invalidated' => true,
                ],
            );
        }

        return response()->json(
            [
                'status' => 'success',
                'message' => 'Logout completed successfully.',
            ],
            Response::HTTP_OK,
        );
    }

    private function auditFailure(
        ?string $userId,
        int $credentialCount,
        int $revocationFailures,
        bool $cleanupFailed,
    ): void {
        if ($userId === null) {
            return;
        }

        $this->auditTrail->log(
            'auth.logout_browser_failed',
            'Browser logout tidak dapat diselesaikan secara penuh.',
            null,
            $userId,
            [
                'status' => 'logout_incomplete',
                'credential_count' => $credentialCount,
                'revocation_failures' => $revocationFailures,
                'cleanup_failed' => $cleanupFailed,
            ],
        );
    }
}
