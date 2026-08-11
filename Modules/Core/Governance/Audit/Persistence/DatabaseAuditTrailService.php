<?php

declare(strict_types=1);

namespace Modules\Core\Governance\Audit\Persistence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Throwable;

final class DatabaseAuditTrailService implements AuditTrailServiceInterface
{
    private const MAX_EVENT_TYPE_LENGTH = 100;

    /**
     * @var list<string>
     */
    private const SENSITIVE_METADATA_KEYS = [
        'access_token',
        'api_key',
        'client_secret',
        'password',
        'password_confirmation',
        'refresh_token',
        'secret',
        'token',
    ];

    public function log(
        string $eventType,
        string $description,
        ?string $tenantId = null,
        ?string $actorUserId = null,
        ?array $metadata = null,
    ): void {
        try {
            $sanitizedMetadata = $metadata !== null
                ? $this->sanitizeMetadata($metadata)
                : null;

            $ipAddress = request()->ip();
            $userAgent = request()->userAgent();

            DB::table('audit_logs')->insert([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenantId,
                'actor_user_id' => $actorUserId,
                'event_type' => substr(
                    $eventType,
                    0,
                    self::MAX_EVENT_TYPE_LENGTH,
                ),
                'description' => $description,
                'metadata' => $sanitizedMetadata !== null
                    ? json_encode(
                        $sanitizedMetadata,
                        JSON_THROW_ON_ERROR,
                    )
                    : null,
                'ip_address' => $ipAddress !== null
                    ? substr($ipAddress, 0, 45)
                    : null,
                'user_agent' => $userAgent,
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::critical(
                'Audit Trail Engine failed to persist log data.',
                [
                    'error' => $exception->getMessage(),
                    'event_type' => $eventType,
                    'actor_user_id' => $actorUserId,
                ],
            );
        }
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function sanitizeMetadata(array $metadata): array
    {
        array_walk_recursive(
            $metadata,
            static function (mixed &$value, mixed $key): void {
                if (
                    in_array(
                        strtolower((string) $key),
                        self::SENSITIVE_METADATA_KEYS,
                        true,
                    )
                ) {
                    $value = '********';
                }
            },
        );

        return $metadata;
    }
}
