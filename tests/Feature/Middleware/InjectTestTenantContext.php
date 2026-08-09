<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Core\Authorization\Contracts\MembershipContextResolverInterface;
use Modules\Core\Authorization\Exceptions\MembershipContextResolutionException;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

final class InjectTestTenantContext
{
    private const TENANT_HEADER = 'X-Tenant-ID';

    private const MEMBERSHIP_HEADER =
    'X-Test-Authenticated-Membership-ID';

    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly MembershipContextResolverInterface $membershipContextResolver,
    ) {}

    /**
     * Menyediakan authentication, tenant, dan membership context
     * untuk integration test.
     *
     * Perbedaan dengan production middleware hanya pada sumber
     * authentication context:
     *
     * Production:
     * Bearer token tervalidasi.
     *
     * Testing:
     * actingAs() + explicit test-only headers.
     *
     * X-Test-Authenticated-Membership-ID mensimulasikan membership_id
     * yang pada production berasal dari bearer token tervalidasi.
     */
    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $userId = trim(
            (string) $user->getAuthIdentifier(),
        );

        if ($userId === '') {
            return $this->incompleteContextResponse();
        }

        $tenantId = $this->normalizeUuid(
            $request->header(
                self::TENANT_HEADER,
            ),
        );

        if ($tenantId === null) {
            return $this->incompleteContextResponse();
        }

        $membershipId = $this->normalizeUuid(
            $request->header(
                self::MEMBERSHIP_HEADER,
            ),
        );

        if ($membershipId === null) {
            return $this->incompleteContextResponse();
        }

        $tenant = Tenant::query()
            ->whereKey($tenantId)
            ->where('is_active', true)
            ->first();

        if ($tenant === null) {
            return $this->incompleteContextResponse();
        }

        $this->tenantContext->setCurrentTenant(
            $tenant,
        );

        /*
         * Mirror canonical attributes yang dibentuk
         * InjectTenantContext production.
         */
        $request->attributes->set(
            'authenticated_user_id',
            $userId,
        );

        $request->attributes->set(
            'authenticated_tenant_id',
            (string) $tenant->getKey(),
        );

        $request->attributes->set(
            'authenticated_membership_id',
            $membershipId,
        );

        try {
            /*
             * Resolver production tetap bertanggung jawab memastikan:
             *
             * - membership exists;
             * - ACTIVE;
             * - milik authenticated user;
             * - berada pada current tenant.
             */
            $this->membershipContextResolver->resolve();

            return $next($request);
        } catch (
            MembershipContextResolutionException $exception
        ) {
            report($exception);

            return response()->json([
                'status' => 'error',
                'message' =>
                'Unauthorized: Your role does not possess the required clearance level for this tenant domain.',
            ], Response::HTTP_FORBIDDEN);
        } finally {
            $this->tenantContext->clear();

            $request->attributes->remove(
                'authenticated_user_id',
            );

            $request->attributes->remove(
                'authenticated_tenant_id',
            );

            $request->attributes->remove(
                'authenticated_membership_id',
            );
        }
    }

    private function normalizeUuid(
        mixed $value,
    ): ?string {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (
            $value === ''
            || ! Str::isUuid($value)
        ) {
            return null;
        }

        return $value;
    }

    private function incompleteContextResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' =>
            'Forbidden: Authentication context is incomplete.',
        ], Response::HTTP_FORBIDDEN);
    }
}
