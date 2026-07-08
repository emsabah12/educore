<?php

namespace Modules\Core\Http\Guards;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Modules\Core\Contracts\TenantContextInterface;
use Illuminate\Support\Facades\Log;

class TenantAwareUserProvider extends EloquentUserProvider
{
    /**
     * Mengambil data pengguna berdasarkan kredensial yang dimasukkan (misal: email).
     * Kita override metode ini agar secara otomatis menyuntikkan current_tenant_id.
     */
    public function retrieveByCredentials(array $credentials): ?UserContract
    {
        if (empty($credentials)) {
            return null;
        }

        // Ambil context tenant aktif saat ini
        $tenantContext = app(TenantContextInterface::class);
        $tenantId = $tenantContext->getCurrentTenantId();

        if ($tenantId === null) {
            Log::warning('Auth attempt blocked: Authentication requested without an active tenant context.');
            return null;
        }

        // Buat query pencarian dasar menggunakan model user
        $query = $this->newModelQuery();

        foreach ($credentials as $key => $value) {
            // Abaikan password karena akan diverifikasi secara terpisah oleh framework via hashing check
            if (str_contains($key, 'password')) {
                continue;
            }

            if (is_array($value) || $value instanceof \Arrayable) {
                $query->whereIn($key, $value);
            } else {
                $query->where($key, $value);
            }
        }

        // KUNCI KEAMANAN MUTLAK: Paksa filter pencarian berdasarkan tenant_id aktif
        $query->where('tenant_id', $tenantId);

        return $query->first();
    }
}