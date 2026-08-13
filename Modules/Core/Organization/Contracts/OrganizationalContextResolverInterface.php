<?php

declare(strict_types=1);

namespace Modules\Core\Organization\Contracts;

use Modules\Core\Organization\Context\OrganizationalContext;

interface OrganizationalContextResolverInterface
{
    public function resolve(
        string $organizationalAssignmentId,
    ): OrganizationalContext;
}
