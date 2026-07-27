<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Authorization\Contracts\AuthorizationServiceInterface;
use Symfony\Component\HttpFoundation\Response;

final class CheckTenantRole
{
    public function __construct(
        private readonly AuthorizationServiceInterface $authorizationService,
    ) {}

    /**
     * Menangani intersepsi HTTP request untuk memvalidasi
     * role kontekstual user di dalam tenant.
     *
     * @param Closure(Request): Response $next
     * @param string $role Nama role yang diizinkan, misalnya "admin".
     */
    public function handle(
        Request $request,
        Closure $next,
        string $role
    ): Response {
        // 1. Pastikan user sudah terautentikasi pada platform.
        $user = Auth::user();

        if ($user === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated access path.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // 2. Superadmin global memiliki akses lintas tenant.
        if ($user->is_superadmin) {
            return $next($request);
        }

        // 3. Resolusi membership context.
        //
        // Prioritas:
        // route parameter
        // -> X-Membership-ID header
        // -> active session context
        $membershipId = $request->route('membership_id')
            ?? $request->header('X-Membership-ID')
            ?? ($request->hasSession()
                ? $request->session()->get('active_membership_id')
                : null);

        if ($membershipId === null || $membershipId === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Bad Request: Missing tenant membership context attributes.',
            ], Response::HTTP_BAD_REQUEST);
        }

        // 4. Resolusi tenant context.
        //
        // Tenant context dapat berasal dari:
        // route parameter
        // -> X-Tenant-ID header
        // -> active session context
        $tenantId = $request->route('tenant_id')
            ?? $request->header('X-Tenant-ID')
            ?? ($request->hasSession()
                ? $request->session()->get('active_tenant_id')
                : null);

        // 5. Delegasikan seluruh business rule authorization
        // kepada AuthorizationService.
        //
        // Service akan memvalidasi:
        // - membership ownership
        // - membership status
        // - tenant ownership
        // - contextual role
        $hasRequiredRole = $this->authorizationService->hasRoleInMembership(
            (string) $user->getAuthIdentifier(),
            (string) $membershipId,
            $role,
            $tenantId !== null ? (string) $tenantId : null,
        );

        if (!$hasRequiredRole) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized: Your role does not possess the required clearance level for this tenant domain.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
