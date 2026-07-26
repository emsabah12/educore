<?php

declare(strict_types=1);

namespace Modules\Core\Services\Auth;

use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DatabaseAuditTrailService implements AuditTrailServiceInterface
{
    /**
     * Merekam aktivitas operasional ke dalam tabel database audit_logs secara aman dan terisolasi.
     */
    public function log(
        string $eventType,
        string $description,
        ?string $tenantId = null,
        ?string $userId = null,
        ?array $payload = null
    ): void {
        try {
            // Bersihkan data payload sensitif sebelum disimpan (seperti raw password)
            $sanitizedPayload = $payload ? $this->sanitizePayload($payload) : null;

            // Ekstrak data teknis lingkungan request secara defensif
            $ipAddress = request()->ip();
            $userAgent = request()->userAgent();

            // Lakukan penulisan data menggunakan Query Builder langsung demi performa terbaik (Append-Only)
            DB::table('audit_logs')->insert([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'event_type' => substr($eventType, 0, 50), // Guard pembatas panjang string kolom
                'description' => $description,
                'payload' => $sanitizedPayload ? json_encode($sanitizedPayload, JSON_THROW_ON_ERROR) : null,
                'ip_address' => $ipAddress ? substr($ipAddress, 0, 45) : null,
                'user_agent' => $userAgent,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Terrapkan Fail-Safe: Kegagalan pencatatan log tidak boleh membuat aplikasi utama crash / down.
            // Kita alihkan kegagalan sistem log ke standard internal Laravel Logging.
            Log::critical('Audit Trail Engine failed to persist log data.', [
                'error' => $e->getMessage(),
                'event_type' => $eventType,
                'user_id' => $userId
            ]);
        }
    }

    /**
     * Menyaring dan menyembunyikan parameter kredensial sensitif demi kepatuhan keamanan data (Data Privacy).
     */
    private function sanitizePayload(array $payload): array
    {
        $sensitiveKeys = ['password', 'password_confirmation', 'secret', 'access_token', 'token'];

        array_walk_recursive($payload, function (&$value, $key) use ($sensitiveKeys) {
            if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                $value = '********';
            }
        });

        return $payload;
    }
}
