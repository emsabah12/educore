<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Modules\Core\Notification\Channels\WhatsAppNotificationChannel;
use Modules\Core\Platform\Notification\Contracts\NotificationChannelInterface;
use Tests\TestCase;

final class NotificationChannelContractTest extends TestCase
{
    public function test_canonical_notification_channel_binding_can_be_resolved(): void
    {
        $channel = $this->app->make(
            NotificationChannelInterface::class,
        );

        $this->assertInstanceOf(
            NotificationChannelInterface::class,
            $channel,
        );

        $this->assertInstanceOf(
            WhatsAppNotificationChannel::class,
            $channel,
        );
    }

    public function test_notification_channel_is_shared_as_registered_singleton(): void
    {
        $firstInstance = $this->app->make(
            NotificationChannelInterface::class,
        );

        $secondInstance = $this->app->make(
            NotificationChannelInterface::class,
        );

        $this->assertSame(
            $firstInstance,
            $secondInstance,
        );
    }
}
