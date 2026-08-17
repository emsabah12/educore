<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\DTO;

final readonly class TenantCapabilityProjection
{
    /**
     * @param array<int, string> $permissions
     */
    public function __construct(
        public string $tenantId,
        public string $membershipId,
        public bool $isGlobalSuperadmin,
        public array $permissions,
    ) {}

    /**
     * @return array{
     *     scope: array{
     *         type: 'tenant',
     *         tenant_id: string,
     *         membership_id: string
     *     },
     *     is_global_superadmin: bool,
     *     permissions: array<int, string>
     * }
     */
    public function toArray(): array
    {
        return [
            'scope' => [
                'type' => 'tenant',
                'tenant_id' => $this->tenantId,
                'membership_id' => $this->membershipId,
            ],
            'is_global_superadmin' =>
            $this->isGlobalSuperadmin,
            'permissions' =>
            $this->permissions,
        ];
    }
}
