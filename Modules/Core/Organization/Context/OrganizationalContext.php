<?php

declare(strict_types=1);

namespace Modules\Core\Organization\Context;

/**
 * Verified runtime organizational placement for the current execution scope.
 *
 * This value object contains identifiers only. It does not grant authorization
 * and it is not an authentication token projection.
 */
final readonly class OrganizationalContext
{
    public function __construct(
        public string $tenantId,
        public string $membershipId,
        public string $assignmentId,
        public string $organizationId,
        public ?string $organizationUnitId,
    ) {
    }
}
