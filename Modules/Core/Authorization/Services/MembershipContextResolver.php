<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Services;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Modules\Core\Authorization\Contracts\MembershipContextResolverInterface;
use Modules\Core\Authorization\DTO\MembershipContext;
use Modules\Core\Authorization\Exceptions\MembershipContextResolutionException;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRepositoryInterface;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;

final readonly class MembershipContextResolver implements MembershipContextResolverInterface
{
    public function __construct(
        private AuthFactory $auth,
        private Request $request,
        private TenantContextInterface $tenantContext,
        private MembershipRepositoryInterface $membershipRepository,
    ) {}

    public function resolve(): MembershipContext
    {
        $userId = $this->resolveAuthenticatedUserId();
        $tenantId = $this->resolveTenantId();
        $requestedMembershipId = $this->resolveRequestedMembershipId();

        $membership = $requestedMembershipId !== null
            ? $this->membershipRepository->findById($requestedMembershipId)
            : $this->membershipRepository->findActiveMembership(
                $userId,
                $tenantId,
            );

        if ($membership === null) {
            throw new MembershipContextResolutionException(
                'Cannot resolve membership context: active membership was not found.',
            );
        }

        $this->assertMembershipIsValid(
            membership: $membership,
            userId: $userId,
            tenantId: $tenantId,
        );

        $membershipId = trim((string) $membership->getKey());

        if ($membershipId === '') {
            throw new MembershipContextResolutionException(
                'Cannot resolve membership context: membership identifier is empty.',
            );
        }

        return new MembershipContext(
            userId: $userId,
            tenantId: $tenantId,
            membershipId: $membershipId,
        );
    }

    private function resolveAuthenticatedUserId(): string
    {
        $user = $this->auth->guard()->user();

        if ($user === null) {
            throw new MembershipContextResolutionException(
                'Cannot resolve membership context: authenticated user is required.',
            );
        }

        $userId = trim((string) $user->getAuthIdentifier());

        if ($userId === '') {
            throw new MembershipContextResolutionException(
                'Cannot resolve membership context: authenticated user identifier is empty.',
            );
        }

        return $userId;
    }

    private function resolveTenantId(): string
    {
        $tenantId = $this->tenantContext->getCurrentTenantId();

        if (! is_string($tenantId) || trim($tenantId) === '') {
            throw new MembershipContextResolutionException(
                'Cannot resolve membership context: tenant context has not been resolved.',
            );
        }

        return trim($tenantId);
    }

    /**
     * Urutan prioritas membership context:
     *
     * 1. authenticated_membership_id dari token tervalidasi
     * 2. Route parameter membership_id
     * 3. X-Membership-ID header
     * 4. active_membership_id session
     * 5. null, lalu resolver menggunakan membership aktif user–tenant
     */
    private function resolveRequestedMembershipId(): ?string
    {
        $membershipId = $this->normalizeIdentifier(
            $this->request->attributes->get(
                'authenticated_membership_id',
            ),
        );

        if ($membershipId !== null) {
            return $membershipId;
        }

        $membershipId = $this->normalizeIdentifier(
            $this->request->route('membership_id'),
        );

        if ($membershipId !== null) {
            return $membershipId;
        }

        $membershipId = $this->normalizeIdentifier(
            $this->request->header('X-Membership-ID'),
        );

        if ($membershipId !== null) {
            return $membershipId;
        }

        if (! $this->request->hasSession()) {
            return null;
        }

        return $this->normalizeIdentifier(
            $this->request->session()->get(
                'active_membership_id',
            ),
        );
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

    private function assertMembershipIsValid(
        Membership $membership,
        string $userId,
        string $tenantId,
    ): void {
        $membershipUserId = trim(
            (string) $membership->getAttribute('user_id'),
        );

        $membershipTenantId = trim(
            (string) $membership->getAttribute('tenant_id'),
        );

        $membershipStatus = strtoupper(trim(
            (string) $membership->getAttribute('status'),
        ));

        if (
            $membershipUserId !== $userId
            || $membershipTenantId !== $tenantId
            || $membershipStatus !== 'ACTIVE'
        ) {
            throw new MembershipContextResolutionException(
                'Cannot resolve membership context: requested membership is not active or does not belong to the authenticated user and tenant.',
            );
        }
    }
}
