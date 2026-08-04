<?php

declare(strict_types=1);

namespace Modules\User\Application\DTO;

final readonly class MembershipSummary
{
    public function __construct(
        public string $membershipId,
        public string $membershipStatus,
        public string $tenantId,
        public string $tenantName,
        public string $tenantSubdomain,
    ) {}

    /**
     * @return array{
     *     membership_id: string,
     *     membership_status: string,
     *     tenant_id: string,
     *     tenant_name: string,
     *     tenant_subdomain: string
     * }
     */
    public function toArray(): array
    {
        return [
            'membership_id' => $this->membershipId,
            'membership_status' => $this->membershipStatus,
            'tenant_id' => $this->tenantId,
            'tenant_name' => $this->tenantName,
            'tenant_subdomain' => $this->tenantSubdomain,
        ];
    }
}
