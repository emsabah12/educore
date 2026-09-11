<?php

declare(strict_types=1);

namespace Modules\Core\Subscription\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\Core\Subscription\Services\TenantSubscriptionService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generic route-level gate untuk SELURUH modul/fitur yang di-gate lewat
 * Subscription (plan atau add-on aktif) — bukan RBAC permission
 * (`tenant.permission`/`organizational.permission` tetap mengurus itu
 * secara terpisah, DIPASANG SETELAH middleware ini). Dipakai pertama
 * kali untuk `hr_module`, tapi ditulis generik lewat parameter
 * `$featureCode` supaya modul berikutnya bisa dipakaikan tanpa
 * middleware baru.
 *
 * WAJIB dipasang SETELAH middleware yang menyuntik
 * `authenticated_tenant_id` (InjectTenantContext /
 * InjectTransportAwareTenantContext) di route chain masing-masing
 * module.
 */
final class CheckTenantFeature
{
    public function __construct(
        private readonly TenantSubscriptionService $tenantSubscriptionService,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(
        Request $request,
        Closure $next,
        string $featureCode,
    ): Response {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $user = Auth::user();

        // Superadmin global tetap bisa lewat panel/API mana pun untuk
        // keperluan dukungan — sama seperti CheckTenantPermission.
        if (
            $user !== null
            && (bool) $user->is_superadmin
        ) {
            return $next($request);
        }

        $effectiveFeatureCodes =
            $this->tenantSubscriptionService
                ->effectiveFeatureCodes($tenantId);

        if (
            ! in_array(
                $featureCode,
                $effectiveFeatureCodes,
                true,
            )
        ) {
            return $this->featureNotAvailableResponse(
                $featureCode,
            );
        }

        return $next($request);
    }

    /**
     * @phpstan-assert-if-true string $value
     */
    private function isCanonicalUuid(mixed $value): bool
    {
        return is_string($value)
            && Str::isUuid(trim($value));
    }

    private function authenticationContextDeniedResponse(): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'AUTHENTICATION_CONTEXT_DENIED',
            message: 'Authentication context missing or invalid.',
            status: Response::HTTP_FORBIDDEN,
        );
    }

    private function featureNotAvailableResponse(
        string $featureCode,
    ): JsonResponse {
        return ApiErrorResponse::make(
            code: 'SUBSCRIPTION_FEATURE_NOT_AVAILABLE',
            message: sprintf(
                'Your tenant\'s current plan does not include the "%s" feature.',
                $featureCode,
            ),
            status: Response::HTTP_FORBIDDEN,
        );
    }
}
