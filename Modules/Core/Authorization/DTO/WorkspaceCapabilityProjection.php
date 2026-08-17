<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\DTO;

final readonly class WorkspaceCapabilityProjection
{
    /**
     * @param array<int, string> $permissions
     */
    public function __construct(
        public string $tenantId,
        public string $membershipId,
        public string $organizationalAssignmentId,
        public string $organizationId,
        public ?string $organizationUnitId,
        public bool $isGlobalSuperadmin,
        public array $permissions,
    ) {}

    /**
     * @return array{
     *     scope: array{
     *         type: 'organization'|'organization_unit',
     *         tenant_id: string,
     *         membership_id: string,
     *         organizational_assignment_id: string,
     *         organization_id: string,
     *         organization_unit_id: string|null
     *     },
     *     is_global_superadmin: bool,
     *     permissions: array<int, string>
     * }
     */
    public function toArray(): array
    {
        return [
            'scope' => [
                'type' =>
                $this->organizationUnitId === null
                    ? 'organization'
                    : 'organization_unit',
                'tenant_id' =>
                $this->tenantId,
                'membership_id' =>
                $this->membershipId,
                'organizational_assignment_id' =>
                $this->organizationalAssignmentId,
                'organization_id' =>
                $this->organizationId,
                'organization_unit_id' =>
                $this->organizationUnitId,
            ],
            'is_global_superadmin' =>
            $this->isGlobalSuperadmin,
            'permissions' =>
            $this->permissions,
        ];
    }
}
