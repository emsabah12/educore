<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Services;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Modules\Core\Authorization\Contracts\MembershipContextResolverInterface;
use Modules\Core\Authorization\DTO\MembershipContext;
use Modules\Core\Authorization\Exceptions\MembershipContextResolutionException;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRepositoryInterface;
use Modules\Core\Identity\Models\User;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;

final readonly class MembershipContextResolver implements MembershipContextResolverInterface
{
    public function __construct(
        private AuthFactory $auth,
        private TenantContextInterface $tenantContext,
        private MembershipRepositoryInterface $membershipRepository,
    ) {}

    public function resolve(): MembershipContext
    {
        $user = $this->resolveAuthenticatedUser();
        $userId = $this->resolveAuthenticatedUserId($user);
        $personId = $this->resolveAuthenticatedPersonId($user);
        $tenantId = $this->resolveTenantId();
        $membershipId = $this->resolveAuthenticatedMembershipId();

        $membership = $this->membershipRepository
            ->findActiveMembershipByIdAndTenant(
                $membershipId,
                $tenantId,
            );

        if ($membership === null) {
            throw new MembershipContextResolutionException(
                'Cannot resolve membership context: active membership was not found.',
            );
        }

        $this->assertMembershipIsValid(
            membership: $membership,
            personId: $personId,
            tenantId: $tenantId,
        );

        $resolvedMembershipId = trim(
            (string) $membership->getKey(),
        );

        if ($resolvedMembershipId === '') {
            throw new MembershipContextResolutionException(
                'Cannot resolve membership context: membership identifier is empty.',
            );
        }

        return new MembershipContext(
            userId: $userId,
            tenantId: $tenantId,
            membershipId: $resolvedMembershipId,
        );
    }

    private function resolveAuthenticatedUser(): User
    {
        $user = $this->auth->guard()->user();

        if (! $user instanceof User) {
            throw new MembershipContextResolutionException(
                'Cannot resolve membership context: canonical authenticated user is required.',
            );
        }

        return $user;
    }

    private function resolveAuthenticatedUserId(User $user): string
    {
        $userId = trim(
            (string) $user->getAuthIdentifier(),
        );

        if ($userId === '') {
            throw new MembershipContextResolutionException(
                'Cannot resolve membership context: authenticated user identifier is empty.',
            );
        }

        return $userId;
    }

    private function resolveAuthenticatedPersonId(User $user): string
    {
        $personId = trim(
            (string) $user->getAttribute('person_id'),
        );

        if ($personId === '') {
            throw new MembershipContextResolutionException(
                'Cannot resolve membership context: authenticated user person identifier is empty.',
            );
        }

        return $personId;
    }

    private function resolveTenantId(): string
    {
        $tenantId = $this->tenantContext->getCurrentTenantId();

        if (
            ! is_string($tenantId)
            || trim($tenantId) === ''
        ) {
            throw new MembershipContextResolutionException(
                'Cannot resolve membership context: tenant context has not been resolved.',
            );
        }

        return trim($tenantId);
    }

    /**
     * Resolve trusted membership context that has already been formed by the
     * authentication middleware. Authorization must not guess membership from
     * user + tenant when the explicit token membership is missing.
     */
    private function resolveAuthenticatedMembershipId(): string
    {
        $membershipId = $this->normalizeIdentifier(
            request()->attributes->get(
                'authenticated_membership_id',
            ),
        );

        if ($membershipId === null) {
            throw new MembershipContextResolutionException(
                'Cannot resolve membership context: authenticated membership identifier is required.',
            );
        }

        return $membershipId;
    }

    private function normalizeIdentifier(
        mixed $value,
    ): ?string {
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
        string $personId,
        string $tenantId,
    ): void {
        $membershipPersonId = trim(
            (string) $membership->getAttribute(
                'person_id',
            ),
        );

        $membershipTenantId = trim(
            (string) $membership->getAttribute(
                'tenant_id',
            ),
        );

        $membershipStatus = strtoupper(trim(
            (string) $membership->getAttribute(
                'status',
            ),
        ));

        if (
            $membershipPersonId !== $personId
            || $membershipTenantId !== $tenantId
            || $membershipStatus !== 'ACTIVE'
        ) {
            throw new MembershipContextResolutionException(
                'Cannot resolve membership context: requested membership is not active or does not belong to the authenticated person and tenant.',
            );
        }
    }
}
