<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Support\Uuid\UuidV7;
use Throwable;

final class AuditTrailService
{
    private string $table = 'auth_audits';

    /**
     * Mencatat aktivitas otentikasi secara langsung ke PostgreSQL.
     * 
     * @param string $tenantUuid
     * @param string|null $userUuid Nullable jika kasusnya LOGIN_FAILED dengan user tak dikenal
     * @param string $eventType contoh: LOGIN_SUCCESS, LOGIN_FAILED, LOGOUT
     * @param array<string, mixed> $metadata Payload tambahan (ex: alasan gagal, device info)
     * @return void
     */
    public function writeAuthAuditLog(
        string $tenantUuid,
        ?string $userUuid,
        string $eventType,
        array $metadata = []
    ): void {
        try {
            // Mengambil metadata request HTTP saat ini secara defensif
            $ipAddress = request()->ip();
            $userAgent = request()->userAgent();

            // Insert-only pattern ke database PostgreSQL menggunakan Query Builder
            DB::table($this->table)->insert([
                'id'          => UuidV7::generate(), // Native UUID v7 dari Kernel Core
                'tenant_uuid' => $tenantUuid,
                'user_uuid'   => $userUuid,
                'event_type'  => strtoupper($eventType),
                'metadata'    => json_encode(array_merge($metadata, [
                    'timestamp_epoch' => time(),
                ])),
                'ip_address'  => $ipAddress,
                'user_agent'  => $userAgent,
                'created_at'  => now()
            ]);
        } catch (Throwable $e) {
            // Kebijakan Fail-Safe: Kegagalan log audit tidak boleh merusak alur bisnis otentikasi utama,
            // namun wajib tercatat di emergency log internal framework.
            Log::emergency(sprintf(
                "Audit Trail Failure: %s | Tenant: %s | User: %s",
                $e->getMessage(),
                $tenantUuid,
                $userUuid ?? 'UNKNOWN'
            ));
        }
    }
}
