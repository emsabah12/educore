<?php

declare(strict_types=1);

namespace Modules\Core\Subscription\Http\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\Core\Subscription\Services\TenantSubscriptionService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only projection of the current tenant's effective Subscription
 * feature codes (plan baseline + active add-ons — see
 * TenantSubscriptionService::effectiveFeatureCodes()). Any
 * authenticated tenant member may read this — it is NOT a management
 * endpoint (that stays superadmin-only, PlatformTenantSubscriptionController).
 *
 * Primary consumer: the frontend's navigation projection, so a menu
 * entry for a feature-gated module (e.g. hr_module) is only shown
 * when the tenant's plan/add-ons actually make it usable — avoiding a
 * dead link that would just 403 from CheckTenantFeature.
 */
final class TenantEffectiveFeaturesController extends Controller
{
    public function __construct(
        private readonly TenantSubscriptionService $tenantSubscriptionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $featureCodes =
            $this->tenantSubscriptionService
            ->effectiveFeatureCodes($tenantId);

        return response()->json([
            'status' => 'success',
            'data' => [
                'feature_codes' => $featureCodes,
            ],
        ]);
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
}
