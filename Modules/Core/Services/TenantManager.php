<?php

namespace Modules\Core\Services;

use Modules\Core\Entities\Tenant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

class TenantManager
{
    /**
     * Membuat data tenant baru di dalam database global.
     *
     * @param array{name: string, subdomain: string, domain: ?string, settings: ?array} $data
     * @return Tenant
     * @throws Exception
     */
    public function createTenant(array $data): Tenant
    {
        // Validasi internal level service untuk menjamin integritas data
        if (empty($data['name']) || empty($data['subdomain'])) {
            throw new \InvalidArgumentException('Tenant name and subdomain are strictly required.');
        }

        // Gunakan Database Transaction demi keamanan data atomik
        return DB::transaction(function () use ($data) {
            Log::info('Initiating tenant provisioning pipeline...', ['subdomain' => $data['subdomain']]);

            $tenant = Tenant::create([
                'name' => $data['name'],
                'subdomain' => strtolower($data['subdomain']),
                'domain' => isset($data['domain']) ? strtolower($data['domain']) : null,
                'is_active' => true,
                'settings' => $data['settings'] ?? [],
            ]);

            Log::info('Tenant provisioned successfully.', ['tenant_id' => $tenant->id]);

            return $tenant;
        });
    }
}