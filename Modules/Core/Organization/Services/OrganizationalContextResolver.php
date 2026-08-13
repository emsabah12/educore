<?php

declare(strict_types=1);

namespace Modules\Core\Organization\Services;

use Modules\Core\Authorization\Contracts\MembershipContextResolverInterface;
use Modules\Core\Authorization\DTO\MembershipContext;
use Modules\Core\Authorization\Exceptions\MembershipContextResolutionException;
use Modules\Core\Organization\Context\OrganizationalContext;
use Modules\Core\Organization\Contracts\OrganizationalAssignmentRepositoryInterface;
use Modules\Core\Organization\Contracts\OrganizationalContextInterface;
use Modules\Core\Organization\Contracts\OrganizationalContextResolverInterface;
use Modules\Core\Organization\Exceptions\OrganizationalContextException;
use Modules\Core\Organization\Models\Organization;
use Modules\Core\Organization\Models\OrganizationalAssignment;
use Modules\Core\Organization\Models\OrganizationUnit;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;

final readonly class OrganizationalContextResolver implements
    OrganizationalContextResolverInterface
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private MembershipContextResolverInterface $membershipContextResolver,
        private OrganizationalAssignmentRepositoryInterface $assignmentRepository,
        private OrganizationalContextInterface $organizationalContext,
    ) {
    }

    public function resolve(
        string $organizationalAssignmentId,
    ): OrganizationalContext {
        /*
         * A failed context switch must never leave a previously resolved
         * organizational context active in the same execution scope.
         */
        $this->organizationalContext->clear();

        $assignmentId = $this->requireUuidV7(
            $organizationalAssignmentId,
            'organizational assignment',
        );

        $tenantId = $this->resolveActiveTenantId();
        $membershipContext = $this->resolveMembershipContext();

        if ($membershipContext->tenantId !== $tenantId) {
            throw new OrganizationalContextException(
                'Cannot resolve organizational context: membership and tenant contexts do not match.',
            );
        }

        $assignment = $this->assignmentRepository
            ->findByIdForMembershipAndTenant(
                $assignmentId,
                $membershipContext->membershipId,
                $tenantId,
            );

        if ($assignment === null) {
            throw new OrganizationalContextException(
                'Cannot resolve organizational context: assignment was not found for the current membership and tenant.',
            );
        }

        if (
            strtoupper(trim((string) $assignment->status))
            !== OrganizationalAssignment::STATUS_ACTIVE
        ) {
            throw new OrganizationalContextException(
                'Cannot resolve organizational context: assignment is inactive.',
            );
        }

        $organizationId = $this->requireStoredUuidV7(
            $assignment->organization_id,
            'organization',
        );

        $this->requireActiveOrganization(
            $organizationId,
            $tenantId,
        );

        $organizationUnitId = $this->resolveOrganizationUnitId(
            $assignment->organization_unit_id,
        );

        if ($organizationUnitId !== null) {
            $this->requireActiveOrganizationUnit(
                $organizationUnitId,
                $organizationId,
                $tenantId,
            );
        }

        $context = new OrganizationalContext(
            tenantId: $tenantId,
            membershipId: $membershipContext->membershipId,
            assignmentId: $assignmentId,
            organizationId: $organizationId,
            organizationUnitId: $organizationUnitId,
        );

        $this->organizationalContext->setCurrentContext(
            $context,
        );

        return $context;
    }

    private function resolveActiveTenantId(): string
    {
        $tenant = $this->tenantContext->getCurrentTenant();

        if (! $tenant instanceof Tenant) {
            throw new OrganizationalContextException(
                'Cannot resolve organizational context: active tenant context is required.',
            );
        }

        $tenantId = trim((string) $tenant->getKey());

        if (
            ! UuidV7::validate($tenantId)
            || ! (bool) $tenant->getAttribute('is_active')
        ) {
            throw new OrganizationalContextException(
                'Cannot resolve organizational context: tenant context is invalid or inactive.',
            );
        }

        return $tenantId;
    }

    private function resolveMembershipContext(): MembershipContext
    {
        try {
            return $this->membershipContextResolver->resolve();
        } catch (MembershipContextResolutionException $exception) {
            throw new OrganizationalContextException(
                'Cannot resolve organizational context: verified membership context is required.',
                previous: $exception,
            );
        }
    }

    private function requireActiveOrganization(
        string $organizationId,
        string $tenantId,
    ): void {
        $exists = Organization::query()
            ->whereKey($organizationId)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->exists();

        if (! $exists) {
            throw new OrganizationalContextException(
                'Cannot resolve organizational context: active organization was not found in the current tenant.',
            );
        }
    }

    private function requireActiveOrganizationUnit(
        string $organizationUnitId,
        string $organizationId,
        string $tenantId,
    ): void {
        $exists = OrganizationUnit::query()
            ->whereKey($organizationUnitId)
            ->where('tenant_id', $tenantId)
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->exists();

        if (! $exists) {
            throw new OrganizationalContextException(
                'Cannot resolve organizational context: active organization unit was not found for the selected organization and tenant.',
            );
        }
    }

    private function resolveOrganizationUnitId(
        mixed $value,
    ): ?string {
        if ($value === null) {
            return null;
        }

        return $this->requireStoredUuidV7(
            $value,
            'organization unit',
        );
    }

    private function requireStoredUuidV7(
        mixed $identifier,
        string $label,
    ): string {
        if (! is_string($identifier)) {
            throw new OrganizationalContextException(
                sprintf(
                    'Cannot resolve organizational context: stored %s identifier is invalid.',
                    $label,
                ),
            );
        }

        return $this->requireUuidV7(
            $identifier,
            $label,
        );
    }

    private function requireUuidV7(
        string $identifier,
        string $label,
    ): string {
        $identifier = trim($identifier);

        if (! UuidV7::validate($identifier)) {
            throw new OrganizationalContextException(
                sprintf(
                    'Cannot resolve organizational context: a valid UUIDv7 %s identifier is required.',
                    $label,
                ),
            );
        }

        return $identifier;
    }
}
