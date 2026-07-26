<?php

namespace Modules\Core\Tenancy\Services;

use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Log;

class TenantContext implements TenantContextInterface
{
    /**
     * Menyimpan runtime state tenant aktif di dalam memori.
     */
    private ?Tenant $currentTenant = null;

    public function setCurrentTenant(Tenant $tenant): void
    {
        $this->currentTenant = $tenant;

        Log::debug('Tenant context bound successfully in memory.', [
            'tenant_id' => $tenant->id,
            'subdomain' => $tenant->subdomain
        ]);
    }

    public function getCurrentTenant(): ?Tenant
    {
        return $this->currentTenant;
    }

    public function getCurrentTenantId(): ?string
    {
        return $this->currentTenant ? $this->currentTenant->id : null;
    }

    public function clear(): void
    {
        $this->currentTenant = null;
    }
}
