<?php

declare(strict_types=1);

namespace Modules\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Jobs\Middleware\RestoreTenantContext;

abstract class BaseTenantAwareJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Tenant yang memiliki job.
     */
    protected string $tenantId;

    /**
     * User atau operator yang memicu job.
     */
    protected ?string $operatorId;

    /**
     * @var array<string, mixed>
     */
    protected array $payload;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        string $tenantId,
        ?string $operatorId,
        array $payload = [],
    ) {
        $this->tenantId = trim(
            $tenantId,
        );

        $this->operatorId = $operatorId !== null
            ? trim($operatorId)
            : null;

        $this->payload = $payload;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new RestoreTenantContext(
                $this->tenantId,
            ),
        ];
    }
}
