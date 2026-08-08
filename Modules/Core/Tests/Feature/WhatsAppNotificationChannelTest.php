<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Core\Notification\Channels\WhatsAppNotificationChannel;
use Modules\Core\Platform\Notification\Contracts\NotificationAttemptStoreInterface;
use Modules\Core\Platform\Notification\Contracts\NotificationChannelInterface;
use Modules\Core\Platform\Notification\Contracts\WhatsAppGatewayInterface;
use Modules\Core\Platform\Notification\DTO\WhatsAppGatewayResult;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class RecordingWhatsAppGateway implements WhatsAppGatewayInterface
{
    public ?string $tenantId = null;

    public ?string $notificationId = null;

    public ?string $recipient = null;

    public ?string $body = null;

    public int $attempts = 0;

    /**
     * @var array<string, mixed>
     */
    public array $options = [];

    /**
     * @var list<WhatsAppGatewayResult>
     */
    private array $results;

    public function __construct(
        WhatsAppGatewayResult ...$results,
    ) {
        if ($results === []) {
            throw new InvalidArgumentException(
                'At least one gateway result is required.',
            );
        }

        $this->results = array_values(
            $results,
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    public function send(
        string $tenantId,
        string $notificationId,
        string $recipient,
        string $body,
        array $options = [],
    ): WhatsAppGatewayResult {
        $this->tenantId = $tenantId;
        $this->notificationId = $notificationId;
        $this->recipient = $recipient;
        $this->body = $body;
        $this->options = $options;

        $resultIndex = min(
            $this->attempts,
            count($this->results) - 1,
        );

        $this->attempts++;

        return $this->results[$resultIndex];
    }
}

final class WhatsAppNotificationChannelTest extends TestCase
{
    use RefreshDatabase;


    public function test_successful_gateway_result_marks_notification_as_sent(): void
    {

        $tenantId = $this->createTenant();
        $notificationId = UuidV7::generate();

        $gateway = new RecordingWhatsAppGateway(
            WhatsAppGatewayResult::success([
                'provider_message_id' => 'provider-message-123',
                'provider_status' => 'queued',
            ]),
        );

        $channel = $this->channel(
            $gateway,
        );

        $result = $channel->send(
            tenantId: $tenantId,
            notificationId: $notificationId,
            recipient: '089987654321',
            body: 'Successful WhatsApp notification.',
            options: [
                'title' => 'Success Test',
            ],
        );

        $this->assertTrue(
            $result['success'],
        );

        $this->assertSame(
            $notificationId,
            $result['log_id'],
        );

        $this->assertNull(
            $result['error'],
        );

        $this->assertSame(
            $tenantId,
            $gateway->tenantId,
        );

        $this->assertSame(
            $notificationId,
            $gateway->notificationId,
        );

        $this->assertDatabaseHas(
            'notification_logs',
            [
                'id' => $notificationId,
                'tenant_id' => $tenantId,
                'recipient' => '089987654321',
                'channel' => 'WHATSAPP',
                'status' => 'SENT',
                'failure_reason' => null,
            ],
        );
    }

    public function test_gateway_rejection_marks_notification_as_failed_with_sanitized_reason(): void
    {
        $tenantId = $this->createTenant();
        $notificationId = UuidV7::generate();

        $gateway = new RecordingWhatsAppGateway(
            WhatsAppGatewayResult::failure(
                'provider_rejected',
                [
                    'provider_status' => 'rejected',
                ],
            ),
        );

        $channel = $this->channel(
            $gateway,
        );

        $result = $channel->send(
            tenantId: $tenantId,
            notificationId: $notificationId,
            recipient: '089987654321',
            body: 'Rejected WhatsApp notification.',
        );

        $this->assertFalse(
            $result['success'],
        );

        $this->assertSame(
            'WhatsApp provider rejected the delivery request.',
            $result['error'],
        );

        $this->assertDatabaseHas(
            'notification_logs',
            [
                'id' => $notificationId,
                'tenant_id' => $tenantId,
                'status' => 'FAILED',
                'failure_reason' =>
                'WhatsApp provider rejected the delivery request.',
            ],
        );
    }

    public function test_default_gateway_fails_closed_instead_of_reporting_false_success(): void
    {
        $tenantId = $this->createTenant();
        $notificationId = UuidV7::generate();

        $channel = $this->app->make(
            NotificationChannelInterface::class,
        );

        $result = $channel->send(
            tenantId: $tenantId,
            notificationId: $notificationId,
            recipient: '089987654321',
            body: 'Unconfigured gateway notification.',
        );

        $this->assertFalse(
            $result['success'],
        );

        $this->assertSame(
            'WhatsApp gateway is not configured.',
            $result['error'],
        );

        $this->assertDatabaseHas(
            'notification_logs',
            [
                'id' => $notificationId,
                'tenant_id' => $tenantId,
                'status' => 'FAILED',
                'failure_reason' =>
                'WhatsApp gateway is not configured.',
            ],
        );
    }

    public function test_retry_reuses_same_notification_log(): void
    {
        $tenantId = $this->createTenant();
        $notificationId = UuidV7::generate();

        $gateway = new RecordingWhatsAppGateway(
            WhatsAppGatewayResult::failure(
                'provider_rejected',
            ),
            WhatsAppGatewayResult::success([
                'provider_message_id' =>
                'provider-retry-success',
            ]),
        );

        $channel = $this->channel(
            $gateway,
        );

        $firstResult = $channel->send(
            tenantId: $tenantId,
            notificationId: $notificationId,
            recipient: '089987654321',
            body: 'Retry notification.',
        );

        $this->assertFalse(
            $firstResult['success'],
        );

        $secondResult = $channel->send(
            tenantId: $tenantId,
            notificationId: $notificationId,
            recipient: '089987654321',
            body: 'Retry notification.',
        );

        $this->assertTrue(
            $secondResult['success'],
        );

        $this->assertSame(
            2,
            $gateway->attempts,
        );

        $this->assertSame(
            1,
            DB::table('notification_logs')
                ->where('id', $notificationId)
                ->count(),
        );

        $this->assertDatabaseHas(
            'notification_logs',
            [
                'id' => $notificationId,
                'tenant_id' => $tenantId,
                'status' => 'SENT',
            ],
        );
    }

    public function test_already_sent_notification_is_not_sent_to_gateway_again(): void
    {
        $tenantId = $this->createTenant();
        $notificationId = UuidV7::generate();

        $gateway = new RecordingWhatsAppGateway(
            WhatsAppGatewayResult::success([
                'provider_message_id' =>
                'provider-idempotent-success',
            ]),
        );

        $channel = $this->channel(
            $gateway,
        );

        $firstResult = $channel->send(
            tenantId: $tenantId,
            notificationId: $notificationId,
            recipient: '089987654321',
            body: 'Idempotent notification.',
        );

        $secondResult = $channel->send(
            tenantId: $tenantId,
            notificationId: $notificationId,
            recipient: '089987654321',
            body: 'Idempotent notification.',
        );

        $this->assertTrue(
            $firstResult['success'],
        );

        $this->assertTrue(
            $secondResult['success'],
        );

        $this->assertSame(
            1,
            $gateway->attempts,
        );

        $this->assertSame(
            1,
            DB::table('notification_logs')
                ->where('id', $notificationId)
                ->count(),
        );
    }

    public function test_notification_identity_collision_across_tenants_is_rejected_before_gateway_execution(): void
    {
        $tenantAId = $this->createTenant();
        $tenantBId = $this->createTenant();
        $notificationId = UuidV7::generate();

        DB::table('notification_logs')->insert([
            'id' => $notificationId,
            'tenant_id' => $tenantAId,
            'user_id' => null,
            'recipient' => '081111111111',
            'channel' => 'WHATSAPP',
            'title' => 'Tenant A Notification',
            'body' => 'Existing notification owned by tenant A.',
            'status' => 'PENDING',
            'failure_reason' => null,
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $gateway = new RecordingWhatsAppGateway(
            WhatsAppGatewayResult::success([
                'provider_message_id' => 'must-not-be-used',
            ]),
        );

        $channel = $this->channel(
            $gateway,
        );

        try {
            $channel->send(
                tenantId: $tenantBId,
                notificationId: $notificationId,
                recipient: '082222222222',
                body: 'Tenant B must not reuse tenant A notification ID.',
            );

            $this->fail(
                'Cross-tenant notification identity collision must be rejected.',
            );
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Notification identity collision was detected.',
                $exception->getMessage(),
            );
        }

        /*
     * Gateway tidak boleh pernah menerima request tenant B karena
     * collision harus dideteksi pada persistence boundary terlebih dahulu.
     */
        $this->assertSame(
            0,
            $gateway->attempts,
        );

        /*
     * Existing durable attempt milik tenant A tidak boleh berubah.
     */
        $this->assertDatabaseHas(
            'notification_logs',
            [
                'id' => $notificationId,
                'tenant_id' => $tenantAId,
                'recipient' => '081111111111',
                'channel' => 'WHATSAPP',
                'status' => 'PENDING',
            ],
        );

        $this->assertDatabaseMissing(
            'notification_logs',
            [
                'id' => $notificationId,
                'tenant_id' => $tenantBId,
            ],
        );

        $this->assertSame(
            1,
            DB::table('notification_logs')
                ->where('id', $notificationId)
                ->count(),
        );
    }

    private function channel(
        WhatsAppGatewayInterface $gateway,
    ): WhatsAppNotificationChannel {
        return new WhatsAppNotificationChannel(
            gateway: $gateway,
            attemptStore: $this->app->make(
                NotificationAttemptStoreInterface::class,
            ),
        );
    }

    private function createTenant(): string
    {
        $tenantId = UuidV7::generate();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => sprintf(
                'WhatsApp Channel Tenant %s',
                substr($tenantId, 0, 8),
            ),
            'subdomain' => sprintf(
                'wa-channel-%s',
                substr(
                    str_replace('-', '', $tenantId),
                    0,
                    16,
                ),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }
}
