<?php

declare(strict_types=1);

namespace Modules\User\Application\DTO;

final readonly class RoleAssignmentResult
{
    public function __construct(
        public string $actorUserId,
        public string $actorMembershipId,
        public string $tenantId,
        public string $targetMembershipId,
        public string $roleId,
    ) {}
}
