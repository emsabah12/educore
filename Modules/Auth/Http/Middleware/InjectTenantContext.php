<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Symfony\Component\HttpFoundation\Response;

final class InjectTenantContext
{
    public function __construct(
        private readonly TokenManagerInterface $tokenManager,
        private readonly TenantContextInterface $tenantContext,
    ) {}

    /**
     * Inject authenticated user and tenant context into the current request.
     *
     * Token parsing and validation are delegated entirely to TokenManagerInterface.
     */
    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        $userUuid = null;
        $tenantUuid = null;


        $bearerToken = $request->bearerToken();

        if ($bearerToken !== null && $bearerToken !== '') {
            $payload = $this->tokenManager->validateAndExtract($bearerToken);

            if ($payload !== null) {
                $userUuid = $this->extractStringClaim(
                    $payload,
                    'user_id',
                );

                $tenantUuid = $this->extractStringClaim(
                    $payload,
                    'tenant_id',
                );
            }
        }


        if ($userUuid === null || $tenantUuid === null) {
            return $this->contextErrorResponse();
        }

        /*
         * Canonical tenant runtime context.
         *
         * TenantContextInterface is now the single source of truth
         * for the currently authenticated tenant.
         */
        $tenant = \Modules\Core\Tenancy\Models\Tenant::query()
            ->find($tenantUuid);

        if ($tenant === null || ! $tenant->is_active) {
            return $this->contextErrorResponse();
        }

        $this->tenantContext->setCurrentTenant($tenant);

        /*
         * User context remains available through the application container
         * for existing consumers until user-context consolidation is completed.
         */

        /*
         * Canonical HTTP request attributes.
         */
        $request->attributes->set(
            'authenticated_tenant_id',
            $tenantUuid,
        );

        $request->attributes->set(
            'authenticated_user_id',
            $userUuid,
        );

        return $next($request);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractStringClaim(
        array $payload,
        string $claim,
    ): ?string {
        $value = $payload[$claim] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== ''
            ? $value
            : null;
    }


    private function contextErrorResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Authentication context missing or invalid.',
        ], Response::HTTP_FORBIDDEN);
    }
}
