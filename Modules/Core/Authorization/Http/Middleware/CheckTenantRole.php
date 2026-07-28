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
     * Tenant context wajib berasal dari authenticated request context
     * yang sudah divalidasi oleh InjectTenantContext.
     *
     * @param Closure(Request): Response $next
     * @param string $role Nama role yang diizinkan, misalnya "admin".
     */
    public function handle(
        Request $request,
        Closure $next,
        string $role
    ): Response {
        $user = Auth::user();

        if ($user === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated access path.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if ($user->is_superadmin) {
            return $next($request);
        }

        $authenticatedUserId = $this->resolveAuthenticatedUserId($request);
        $authenticatedTenantId = $this->resolveAuthenticatedTenantId($request);
        $membershipId = $this->resolveMembershipId($request);

        if ($authenticatedUserId === null || $authenticatedTenantId === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Forbidden: Authentication context is incomplete.',
            ], Response::HTTP_FORBIDDEN);
        }

        if ($membershipId === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Bad Request: Missing tenant membership context attributes.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $hasRequiredRole = $this->authorizationService->hasRoleInMembership(
            $authenticatedUserId,
            $membershipId,
            $role,
            $authenticatedTenantId
        );

        if (! $hasRequiredRole) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized: Your role does not possess the required clearance level for this tenant domain.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    /**
     * Mengambil authenticated user ID dari canonical request context.
     */
    private function resolveAuthenticatedUserId(Request $request): ?string
    {
        $userId = $request->attributes->get('authenticated_user_id');

        if (! is_string($userId)) {
            return null;
        }

        $userId = trim($userId);

        return $userId !== '' ? $userId : null;
    }

    /**
     * Mengambil authenticated tenant ID dari canonical request context.
     *
     * Sumber kebenaran tenant authorization hanya request attribute
     * yang telah diisi oleh InjectTenantContext.
     */
    private function resolveAuthenticatedTenantId(Request $request): ?string
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (! is_string($tenantId)) {
            return null;
        }

        $tenantId = trim($tenantId);

        return $tenantId !== '' ? $tenantId : null;
    }

    /**
     * Mengambil membership context yang sedang aktif.
     *
     * Urutan fallback tetap dipertahankan untuk konteks membership,
     * tetapi tenant authorization tidak lagi membaca X-Tenant-ID.
     */
    private function resolveMembershipId(Request $request): ?string
    {
        $membershipId = $request->route('membership_id');

        if (! is_string($membershipId) || trim($membershipId) === '') {
            $membershipId = $request->header('X-Membership-ID');

            if (! is_string($membershipId) || trim($membershipId) === '') {
                $membershipId = $request->hasSession()
                    ? $request->session()->get('active_membership_id')
                    : null;
            }
        }

        if (! is_string($membershipId)) {
            return null;
        }

        $membershipId = trim($membershipId);

        return $membershipId !== '' ? $membershipId : null;
    }
}
