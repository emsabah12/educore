<?php

declare(strict_types=1);

namespace Modules\User\Application\DTO;

use Illuminate\Support\Collection;

final readonly class WorkspaceDiscoveryResult
{
    /**
     * @param Collection<int, WorkspaceSummary> $workspaces
     */
    public function __construct(
        public string $tenantId,
        public string $tenantName,
        public Collection $workspaces,
    ) {}
}
