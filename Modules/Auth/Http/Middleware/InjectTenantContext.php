<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Symfony\Component\HttpFoundation\Response;

final class InjectTenantContext
{
    public function __construct(
        private readonly TokenManagerInterface $tokenManager
    ) {}

    /**
     * Inject authenticated user and tenant context into the current request.
     *
     * Token parsing and validation are delegated entirely to TokenManagerInterface.
     * This middleware must not know whether the token is JWT, encrypted, opaque,
     * or implemented using another token strategy.
     */
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $userUuid = null;
        $tenantUuid = null;

        /*
         * --------------------------------------------------------------------------
         * 1. Resolve Authentication Context from Bearer Token
         * --------------------------------------------------------------------------
         *
         * The middleware delegates token parsing and validation to the token manager.
         *
         * This is the single source of truth for token handling.
         *
         * The middleware must never:
         *
         * - manually decode JWT payloads;
         * - manually decrypt tokens;
         * - trust unsigned token payloads;
         * - assume a specific token format.
         */
        $bearerToken = $request->bearerToken();

        if ($bearerToken !== null && $bearerToken !== '') {
            $payload = $this->tokenManager->validateAndExtract($bearerToken);

            if ($payload !== null) {
                $userUuid = $this->extractStringClaim(
                    $payload,
                    'user_id'
                );

                $tenantUuid = $this->extractStringClaim(
                    $payload,
                    'tenant_id'
                );
            }
        }

        /*
         * --------------------------------------------------------------------------
         * 2. Validate Authentication Context
         * --------------------------------------------------------------------------
         *
         * A valid token must contain both user_id and tenant_id.
         *
         * At this stage we only validate that the token contains the required
         * structural claims.
         *
         * IMPORTANT:
         * Membership authorization is intentionally NOT handled here yet.
         *
         * That responsibility will be implemented in the next security boundary
         * step through Membership Resolution.
         */
        if ($userUuid === null || $tenantUuid === null) {
            return $this->contextErrorResponse();
        }

        /*
         * --------------------------------------------------------------------------
         * 3. Bind Tenant and User Context to Service Container
         * --------------------------------------------------------------------------
         *
         * The context is scoped to the current application request lifecycle.
         */
        app()->instance(
            'current_tenant_uuid',
            $tenantUuid
        );

        app()->instance(
            'current_user_uuid',
            $userUuid
        );

        /*
         * --------------------------------------------------------------------------
         * 4. Bind Canonical Request Attributes
         * --------------------------------------------------------------------------
         *
         * These attributes are the canonical context contract consumed by
         * downstream controllers and services.
         */
        $request->attributes->set(
            'authenticated_tenant_id',
            $tenantUuid
        );

        $request->attributes->set(
            'authenticated_user_id',
            $userUuid
        );

        /*
         * --------------------------------------------------------------------------
         * 5. Backward Compatibility Attributes
         * --------------------------------------------------------------------------
         *
         * These aliases are temporarily retained for legacy consumers.
         *
         * New code should use:
         *
         * - authenticated_tenant_id
         * - authenticated_user_id
         */
        $request->attributes->set(
            'tenant_uuid',
            $tenantUuid
        );

        $request->attributes->set(
            'user_uuid',
            $userUuid
        );

        return $next($request);
    }

    /**
     * Extract a non-empty string claim from a validated token payload.
     *
     * @param array<string, mixed> $payload
     */
    private function extractStringClaim(
        array $payload,
        string $claim
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

    /**
     * Return a generic authentication context error.
     *
     * The response intentionally does not expose whether the token was:
     *
     * - missing;
     * - malformed;
     * - expired;
     * - tampered with;
     * - missing required claims.
     *
     * This prevents unnecessary information disclosure to clients.
     */
    private function contextErrorResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Authentication context missing or invalid.',
        ], Response::HTTP_FORBIDDEN);
    }
}
