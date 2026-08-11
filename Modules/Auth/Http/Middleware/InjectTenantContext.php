<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Auth\Application\Services\AuthenticatedIdentityResolver;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRepositoryInterface;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Contracts\TenantRuntimeResolverInterface;
use Symfony\Component\HttpFoundation\Response;

final class InjectTenantContext
{
    public function __construct(
        private readonly AuthenticatedIdentityResolver $identityResolver,
        private readonly AuthFactory $auth,
        private readonly TenantContextInterface $tenantContext,
        private readonly TenantRuntimeResolverInterface $tenantRuntimeResolver,
        private readonly MembershipRepositoryInterface $membershipRepository,
    ) {}

    /**
     * Build the canonical request authentication + tenant context.
     *
     * Bearer token
     *   ↓
     * Active User account
     *   ↓
     * User.person_id
     *   ↓
     * Active Membership(person_id, tenant_id)
     *   ↓
     * Active Tenant
     *   ↓
     * request-local Auth Guard + TenantContext
     */
    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        $bearerToken = $request->bearerToken();

        if (! is_string($bearerToken)) {
            return $this->contextErrorResponse();
        }

        $identity = $this->identityResolver->resolve(
            $bearerToken,
        );

        if ($identity === null) {
            return $this->contextErrorResponse();
        }

        $tenantId = $identity->stringClaim(
            'tenant_id',
        );

        if (
            $tenantId === null
            || ! Str::isUuid($tenantId)
        ) {
            return $this->contextErrorResponse();
        }

        $membershipId = $identity->stringClaim(
            'membership_id',
        );

        if (
            $membershipId === null
            || ! Str::isUuid($membershipId)
        ) {
            return $this->contextErrorResponse();
        }

        $personId = trim(
            (string) $identity->user->person_id,
        );

        if ($personId === '') {
            return $this->contextErrorResponse();
        }

        $membership = $this->membershipRepository
            ->findActiveMembershipByIdAndTenant(
                $membershipId,
                $tenantId,
            );

        if (
            $membership === null
            || trim((string) $membership->person_id) !== $personId
        ) {
            return $this->contextErrorResponse();
        }

        $tenant = $this->tenantRuntimeResolver
            ->findActiveById(
                $tenantId,
            );

        if ($tenant === null) {
            return $this->contextErrorResponse();
        }

        $guard = $this->auth->guard();

        $guard->setUser(
            $identity->user,
        );

        $this->tenantContext->setCurrentTenant(
            $tenant,
        );

        $request->attributes->set(
            'authenticated_user_id',
            $identity->userId,
        );

        $request->attributes->set(
            'authenticated_tenant_id',
            $tenantId,
        );

        $request->attributes->set(
            'authenticated_membership_id',
            $membershipId,
        );

        try {
            return $next($request);
        } finally {
            $this->tenantContext->clear();
            $guard->forgetUser();

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

    private function contextErrorResponse(): JsonResponse
    {
        return response()->json(
            [
                'status' => 'error',
                'message' => 'Authentication context missing or invalid.',
            ],
            Response::HTTP_FORBIDDEN,
        );
    }
}
