<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Exceptions;

use RuntimeException;
use Throwable;

final class TenantContextMismatchException extends RuntimeException
{
    public function __construct(
        string $modelClass,
        string $activeTenantId,
        string $modelTenantId,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Tenant context mismatch detected for model [%s]. Active tenant [%s] does not match model tenant [%s].',
                $modelClass,
                $activeTenantId,
                $modelTenantId,
            ),
            0,
            $previous,
        );
    }
}
