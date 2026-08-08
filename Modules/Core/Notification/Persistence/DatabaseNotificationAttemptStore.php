<?php

declare(strict_types=1);

namespace Modules\Core\Notification\Persistence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Core\Platform\Notification\Contracts\NotificationAttemptStoreInterface;
use Modules\Core\Platform\Notification\DTO\PreparedNotificationAttempt;
use RuntimeException;
use Throwable;

final class DatabaseNotificationAttemptStore implements NotificationAttemptStoreInterface
{
    private const TABLE = 'notification_logs';

    public function prepareAttempt(
        string $tenantId,
        string $notificationId,
        ?string $userId,
        string $recipient,
        string $channel,
        ?string $title,
        string $body,
    ): PreparedNotificationAttempt {
        $tenantId = $this->requireUuid(
            $tenantId,
            'Tenant identifier',
        );

        $notificationId = $this->requireUuid(
            $notificationId,
            'Notification identifier',
        );

        $recipient = trim($recipient);
        $channel = strtoupper(trim($channel));
        $body = trim($body);

        if ($recipient === '') {
            throw new InvalidArgumentException(
                'Notification recipient is required.',
            );
        }

        if ($channel === '') {
            throw new InvalidArgumentException(
                'Notification channel is required.',
            );
        }

        if ($body === '') {
            throw new InvalidArgumentException(
                'Notification body is required.',
            );
        }

        if ($userId !== null) {
            $userId = trim($userId);

            if (
                $userId === ''
                || ! Str::isUuid($userId)
            ) {
                throw new InvalidArgumentException(
                    'Notification user identifier is invalid.',
                );
            }
        }

        if ($title !== null) {
            $title = trim($title);

            if ($title === '') {
                $title = null;
            }
        }

        $now = now();

        /*
         * Notification ID adalah primary key global sehingga insertOrIgnore
         * membuat proses redelivery/retry tidak menghasilkan row kedua.
         */
        DB::table(self::TABLE)->insertOrIgnore([
            'id' => $notificationId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'recipient' => $recipient,
            'channel' => $channel,
            'title' => $title,
            'body' => $body,
            'status' => 'PENDING',
            'failure_reason' => null,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /*
         * Lookup sengaja berdasarkan global notification ID tanpa
         * tenant filter.
         *
         * Setelah row ditemukan, ownership tenant + channel diverifikasi.
         * Ini mempertahankan collision detection lintas tenant/channel.
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

        if ($status === 'SENT') {
            return new PreparedNotificationAttempt(
                alreadySent: true,
                metadata: $this->decodeMetadata(
                    $attempt->metadata ?? null,
                ),
            );
        }

        /*
         * FAILED/PENDING redelivery menggunakan durable row yang sama.
         */
        $updated = DB::table(self::TABLE)
            ->where('id', $notificationId)
            ->where('tenant_id', $tenantId)
            ->where('channel', $channel)
            ->update([
                'status' => 'PENDING',
                'failure_reason' => null,
                'metadata' => null,
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
        array $metadata = [],
    ): void {
        $tenantId = $this->requireUuid(
            $tenantId,
            'Tenant identifier',
        );

        $notificationId = $this->requireUuid(
            $notificationId,
            'Notification identifier',
        );

        $updated = DB::table(self::TABLE)
            ->where('id', $notificationId)
            ->where('tenant_id', $tenantId)
            ->update([
                'status' => 'SENT',
                'failure_reason' => null,
                'metadata' => $this->encodeMetadata(
                    $metadata,
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
        string $failureReason,
        array $metadata = [],
    ): void {
        $tenantId = $this->requireUuid(
            $tenantId,
            'Tenant identifier',
        );

        $notificationId = $this->requireUuid(
            $notificationId,
            'Notification identifier',
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
                'status' => 'FAILED',
                'failure_reason' => $failureReason,
                'metadata' => $this->encodeMetadata(
                    $metadata,
                ),
                'updated_at' => now(),
            ]);

        if ($updated < 1) {
            throw new RuntimeException(
                'Notification attempt could not be marked as failed.',
            );
        }
    }

    private function requireUuid(
        string $value,
        string $label,
    ): string {
        $value = trim($value);

        if (
            $value === ''
            || ! Str::isUuid($value)
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    '%s is invalid.',
                    $label,
                ),
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function encodeMetadata(
        array $metadata,
    ): ?string {
        if ($metadata === []) {
            return null;
        }

        return json_encode(
            $metadata,
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMetadata(
        mixed $metadata,
    ): array {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (
            ! is_string($metadata)
            || trim($metadata) === ''
        ) {
            return [];
        }

        try {
            $decoded = json_decode(
                $metadata,
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
