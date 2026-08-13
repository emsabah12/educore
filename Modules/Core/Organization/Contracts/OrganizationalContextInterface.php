<?php

declare(strict_types=1);

namespace Modules\Core\Organization\Contracts;

use Modules\Core\Organization\Context\OrganizationalContext;

interface OrganizationalContextInterface
{
    public function setCurrentContext(
        OrganizationalContext $context,
    ): void;

    public function getCurrentContext(): ?OrganizationalContext;

    public function clear(): void;
}
