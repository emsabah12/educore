<?php

declare(strict_types=1);

namespace Modules\Core\Jobs\Middleware;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use RuntimeException;

final readonly class RestoreTenantContext
{
    public function __construct(
        private string $tenantId,
    ) {}

    /**
     * Memulihkan tenant context pada lifecycle queue job.
     *
     * Context selalu dibersihkan setelah job selesai, baik berhasil
     * maupun melempar exception.
     */
    public function handle(
        object $job,
        callable $next,
    ): mixed {
        $tenantId = trim(
            $this->tenantId,
        );

        if (
            $tenantId === ''
            || ! Str::isUuid($tenantId)
        ) {
            throw new InvalidArgumentException(
                'Queue tenant context is invalid.',
            );
        }

        /** @var TenantContextInterface $tenantContext */
        $tenantContext = app(
            TenantContextInterface::class,
        );

        /*
         * Defense-in-depth terhadap state yang mungkin tersisa
         * pada worker lifecycle yang sama.
         */
        $tenantContext->clear();

        $tenant = Tenant::query()
            ->whereKey($tenantId)
            ->where('is_active', true)
            ->first();

        if ($tenant === null) {
            throw new RuntimeException(
                'Queue tenant context could not be resolved.',
            );
        }

        $tenantContext->setCurrentTenant(
            $tenant,
        );

        try {
            return $next($job);
        } finally {
            $tenantContext->clear();
        }
    }
}
