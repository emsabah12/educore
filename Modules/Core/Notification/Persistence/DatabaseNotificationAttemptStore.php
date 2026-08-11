<?php

declare(strict_types=1);

namespace Modules\Core\Notification\Persistence;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Core\Platform\Notification\Contracts\NotificationAttemptStoreInterface;
use Modules\Core\Platform\Notification\DTO\PreparedNotificationAttempt;
use Modules\Core\Support\Uuid\UuidV7;
use RuntimeException;
use Throwable;

final class DatabaseNotificationAttemptStore implements NotificationAttemptStoreInterface
{
    private const TABLE = 'notification_attempts';

    private const STATUS_PENDING = 'PENDING';

    private const STATUS_SENT = 'SENT';

    private const STATUS_FAILED = 'FAILED';

    public function prepareAttempt(
        string $tenantId,
        string $notificationId,
        string $channel,
    ): PreparedNotificationAttempt {
        $tenantId = $this->requireUuidV7(
            $tenantId,
            'Tenant identifier',
        );

        $notificationId = $this->requireUuidV7(
            $notificationId,
            'Notification identifier',
        );

        $channel = $this->requireChannel($channel);
        $now = now();

        /*
         * Notification ID adalah primary key global sehingga insertOrIgnore
         * membuat proses redelivery/retry tidak menghasilkan row kedua.
         *
         * Durable telemetry sengaja tidak menyimpan recipient, title, body,
         * atau user identifier. Data delivery tersebut tetap transient di
         * queue/channel/gateway boundary.
         */
        DB::table(self::TABLE)->insertOrIgnore([
            'id' => $notificationId,
            'tenant_id' => $tenantId,
            'channel' => $channel,
            'status' => self::STATUS_PENDING,
            'failure_code' => null,
            'failure_reason' => null,
            'provider_metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /*
         * Lookup sengaja berdasarkan global notification ID tanpa tenant
         * filter agar collision lintas tenant/channel tetap terdeteksi.
         */
        $attempt = DB::table(self::TABLE)
            ->where('id', $notificationId)
            ->first();

        if ($attempt === null) {
            throw new RuntimeException(
                'Notification attempt could not be persisted.',
            );
        }

        if (
            (string) $attempt->tenant_id !== $tenantId
            || strtoupper((string) $attempt->channel) !== $channel
        ) {
            throw new RuntimeException(
                'Notification identity collision was detected.',
            );
        }

        $status = strtoupper(
            trim((string) $attempt->status),
        );

        if ($status === self::STATUS_SENT) {
            return new PreparedNotificationAttempt(
                alreadySent: true,
                providerMetadata: $this->decodeProviderMetadata(
                    $attempt->provider_metadata ?? null,
                ),
            );
        }

        /*
         * FAILED/PENDING redelivery menggunakan durable row yang sama dan
         * membersihkan telemetry hasil attempt sebelumnya.
         */
        $updated = DB::table(self::TABLE)
            ->where('id', $notificationId)
            ->where('tenant_id', $tenantId)
            ->where('channel', $channel)
            ->update([
                'status' => self::STATUS_PENDING,
                'failure_code' => null,
                'failure_reason' => null,
                'provider_metadata' => null,
                'updated_at' => $now,
            ]);

        if ($updated < 1) {
            throw new RuntimeException(
                'Notification attempt could not be prepared.',
            );
        }

        return new PreparedNotificationAttempt(
            alreadySent: false,
        );
    }

    public function markSent(
        string $tenantId,
        string $notificationId,
        array $providerMetadata = [],
    ): void {
        $tenantId = $this->requireUuidV7(
            $tenantId,
            'Tenant identifier',
        );

        $notificationId = $this->requireUuidV7(
            $notificationId,
            'Notification identifier',
        );

        $updated = DB::table(self::TABLE)
            ->where('id', $notificationId)
            ->where('tenant_id', $tenantId)
            ->update([
                'status' => self::STATUS_SENT,
                'failure_code' => null,
                'failure_reason' => null,
                'provider_metadata' => $this->encodeProviderMetadata(
                    $providerMetadata,
                ),
                'updated_at' => now(),
            ]);

        if ($updated < 1) {
            throw new RuntimeException(
                'Notification attempt could not be marked as sent.',
            );
        }
    }

    public function markFailed(
        string $tenantId,
        string $notificationId,
        string $failureCode,
        string $failureReason,
        array $providerMetadata = [],
    ): void {
        $tenantId = $this->requireUuidV7(
            $tenantId,
            'Tenant identifier',
        );

        $notificationId = $this->requireUuidV7(
            $notificationId,
            'Notification identifier',
        );

        $failureCode = $this->requireFailureCode(
            $failureCode,
        );

        $failureReason = trim($failureReason);

        if ($failureReason === '') {
            throw new InvalidArgumentException(
                'Notification failure reason is required.',
            );
        }

        $updated = DB::table(self::TABLE)
            ->where('id', $notificationId)
            ->where('tenant_id', $tenantId)
            ->update([
                'status' => self::STATUS_FAILED,
                'failure_code' => $failureCode,
                'failure_reason' => $failureReason,
                'provider_metadata' => $this->encodeProviderMetadata(
                    $providerMetadata,
                ),
                'updated_at' => now(),
            ]);

        if ($updated < 1) {
            throw new RuntimeException(
                'Notification attempt could not be marked as failed.',
            );
        }
    }

    private function requireUuidV7(
        string $value,
        string $label,
    ): string {
        $value = trim($value);

        if (! UuidV7::validate($value)) {
            throw new InvalidArgumentException(
                sprintf(
                    '%s is invalid.',
                    $label,
                ),
            );
        }

        return strtolower($value);
    }

    private function requireChannel(string $channel): string
    {
        $channel = strtoupper(trim($channel));

        if (
            $channel === ''
            || strlen($channel) > 30
        ) {
            throw new InvalidArgumentException(
                'Notification channel is invalid.',
            );
        }

        return $channel;
    }

    private function requireFailureCode(
        string $failureCode,
    ): string {
        $failureCode = strtolower(trim($failureCode));

        if (
            $failureCode === ''
            || preg_match(
                '/\A[a-z0-9._-]{1,64}\z/',
                $failureCode,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Notification failure code is invalid.',
            );
        }

        return $failureCode;
    }

    /**
     * @param array<string, mixed> $providerMetadata
     */
    private function encodeProviderMetadata(
        array $providerMetadata,
    ): ?string {
        if ($providerMetadata === []) {
            return null;
        }

        return json_encode(
            $providerMetadata,
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeProviderMetadata(
        mixed $providerMetadata,
    ): array {
        if (is_array($providerMetadata)) {
            return $providerMetadata;
        }

        if (
            ! is_string($providerMetadata)
            || trim($providerMetadata) === ''
        ) {
            return [];
        }

        try {
            $decoded = json_decode(
                $providerMetadata,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            return is_array($decoded)
                ? $decoded
                : [];
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }
}
