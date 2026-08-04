<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Authorization\Contracts\MembershipContextResolverInterface;
use Modules\Core\Authorization\Exceptions\MembershipContextResolutionException;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

final class InjectTestTenantContext
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly MembershipContextResolverInterface $membershipContextResolver,
    ) {}

    /**
     * Menyediakan authentication, tenant, dan membership context
     * untuk integration test.
     *
     * Perbedaan dengan production middleware hanya pada sumber identity:
     *
     * - Production menggunakan bearer token tervalidasi.
     * - Testing menggunakan actingAs().
     *
     * Membership tetap divalidasi menggunakan resolver production.
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

        $tenantId = $this->normalizeIdentifier(
            $request->header('X-Tenant-ID'),
        );

        if ($tenantId === null) {
            return $this->incompleteContextResponse();
        }

        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null || ! (bool) $tenant->is_active) {
            return $this->incompleteContextResponse();
        }

        /*
         * Canonical runtime tenant context.
         *
         * MembershipContextResolver memerlukan TenantContext
         * yang telah terikat sebelum melakukan validasi membership.
         */
        $this->tenantContext->setCurrentTenant($tenant);

        /*
         * Canonical request attributes yang berasal dari authenticated
         * testing context.
         */
        $request->attributes->set(
            'authenticated_user_id',
            $userId,
        );

        $request->attributes->set(
            'authenticated_tenant_id',
            (string) $tenant->getKey(),
        );

        try {
            /*
             * Jangan menetapkan authenticated_membership_id dari header.
             *
             * Resolver production menentukan membership berdasarkan:
             * 1. authenticated_membership_id jika sudah trusted
             * 2. route membership_id
             * 3. X-Membership-ID
             * 4. session
             * 5. fallback user–tenant
             */
            $membershipContext = $this->membershipContextResolver->resolve();

            /*
             * Setelah tervalidasi, membership baru boleh dipromosikan
             * menjadi trusted request context untuk downstream service.
             */
            $request->attributes->set(
                'authenticated_membership_id',
                $membershipContext->membershipId,
            );

            return $next($request);
        } catch (MembershipContextResolutionException $exception) {
            report($exception);

            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized: Your role does not possess the required clearance level for this tenant domain.',
            ], Response::HTTP_FORBIDDEN);
        } finally {
            /*
             * Hindari state bocor ke test atau request berikutnya.
             */
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

    private function normalizeIdentifier(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== ''
            ? $value
            : null;
    }

    private function incompleteContextResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Forbidden: Authentication context is incomplete.',
        ], Response::HTTP_FORBIDDEN);
    }
}
