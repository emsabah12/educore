<?php

declare(strict_types=1);

namespace Modules\Core\Subscription\Exceptions;

use RuntimeException;

final class CustomRoleFeatureNotAvailableException extends RuntimeException
{
    public function __construct(string $tenantId)
    {
        parent::__construct(sprintf(
            'CUSTOM_ROLE_FEATURE_NOT_AVAILABLE: Tenant [%s] does not currently have the custom_roles feature effective (via plan or active add-on).',
            $tenantId,
        ));
    }
}
