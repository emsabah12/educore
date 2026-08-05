<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Notification\Channels\WhatsAppNotificationChannel;
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

    /**
     * @var array<string, mixed>
     */
    public array $options = [];

    public function __construct(
        private readonly WhatsAppGatewayResult $result,
    ) {}

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

        return $this->result;
    }
}

final class WhatsAppNotificationChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_gateway_result_marks_notification_as_sent(): void
    {
        $tenantId = $this->createTenant();

        $gateway = new RecordingWhatsAppGateway(
            WhatsAppGatewayResult::success([
                'provider_message_id' => 'provider-message-123',
                'provider_status' => 'queued',
            ]),
        );

        $channel = new WhatsAppNotificationChannel(
            $gateway,
        );

        $result = $channel->send(
            tenantId: $tenantId,
            recipient: '089987654321',
            body: 'Successful WhatsApp notification.',
            options: [
                'title' => 'Success Test',
            ],
        );

        $this->assertTrue(
            $result['success'],
        );

        $this->assertNull(
            $result['error'],
        );

        $this->assertSame(
            $tenantId,
            $gateway->tenantId,
        );

        $this->assertSame(
            $result['log_id'],
            $gateway->notificationId,
        );

        $this->assertSame(
            '089987654321',
            $gateway->recipient,
        );

        $this->assertDatabaseHas(
            'notification_logs',
            [
                'id' => $result['log_id'],
                'tenant_id' => $tenantId,
                'recipient' => '089987654321',
                'channel' => 'WHATSAPP',
                'status' => 'SENT',
                'failure_reason' => null,
            ],
        );

        $storedLog = DB::table('notification_logs')
            ->where('id', $result['log_id'])
            ->first();

        $this->assertNotNull(
            $storedLog,
        );

        $metadata = json_decode(
            (string) $storedLog->metadata,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(
            'provider-message-123',
            $metadata['provider_message_id'],
        );
    }

    public function test_gateway_rejection_marks_notification_as_failed_with_sanitized_reason(): void
    {
        $tenantId = $this->createTenant();

        $gateway = new RecordingWhatsAppGateway(
            WhatsAppGatewayResult::failure(
                'provider_rejected',
                [
                    'provider_status' => 'rejected',
                ],
            ),
        );

        $channel = new WhatsAppNotificationChannel(
            $gateway,
        );

        $result = $channel->send(
            tenantId: $tenantId,
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
                'id' => $result['log_id'],
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

        $channel = $this->app->make(
            NotificationChannelInterface::class,
        );

        $result = $channel->send(
            tenantId: $tenantId,
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
                'id' => $result['log_id'],
                'tenant_id' => $tenantId,
                'status' => 'FAILED',
                'failure_reason' =>
                'WhatsApp gateway is not configured.',
            ],
        );

        $this->assertDatabaseMissing(
            'notification_logs',
            [
                'id' => $result['log_id'],
                'status' => 'SENT',
            ],
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
