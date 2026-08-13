<?php

declare(strict_types=1);

namespace Modules\Core\Organization\Services;

use Modules\Core\Organization\Context\OrganizationalContext;
use Modules\Core\Organization\Contracts\OrganizationalContextInterface;

final class OrganizationalContextState implements OrganizationalContextInterface
{
    private ?OrganizationalContext $currentContext = null;

    public function setCurrentContext(
        OrganizationalContext $context,
    ): void {
        $this->currentContext = $context;
    }

    public function getCurrentContext(): ?OrganizationalContext
    {
        return $this->currentContext;
    }

    public function clear(): void
    {
        $this->currentContext = null;
    }
}
