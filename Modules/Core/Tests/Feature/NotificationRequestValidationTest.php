<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Jobs\SendAsynchronousNotificationJob;
use Tests\TestCase;

final class NotificationRequestValidationTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $personId;

    private string $userId;

    private string $membershipId;

    private TokenManagerInterface $tokenManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId =
            '019f62f3-f5b5-7216-9578-0af9cb3b5b54';

        $this->personId =
            '019f62f3-f5b5-7216-9578-0af9cb3b5b53';

        $this->userId =
            '019f62f3-f5b5-7216-9578-0af9cb3b5b55';

        $this->membershipId =
            '019f62f3-f5b5-7216-9578-0af9cb3b5b56';

        $this->tokenManager = $this->app->make(
            TokenManagerInterface::class,
        );

        DB::table('persons')->insert([
            'id' => $this->personId,
            'name' => 'Notification Validation User',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->userId,
            'person_id' => $this->personId,
            'email' => sprintf(
                'notification-validation-%s@educore.test',
                Str::lower(
                    Str::random(10),
                ),
            ),
            'password' => bcrypt('secret123'),
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Notification Validation Tenant',
            'subdomain' => 'notification-validation',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $this->membershipId,
            'person_id' => $this->personId,
            'tenant_id' => $this->tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function issueAuthenticatedToken(): string
    {
        return $this->tokenManager->issueToken(
            $this->userId,
            $this->tenantId,
            [
                'membership_id' => $this->membershipId,
            ],
        );
    }

    public function test_client_cannot_supply_notification_user_identity(): void
    {
        Bus::fake();

        $token = $this->issueAuthenticatedToken();

        $response = $this
            ->withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ])
            ->postJson(
                '/api/v1/core/notifications/dispatch',
                [
                    'recipient' => '089987654321',
                    'body' => 'Notification identity spoofing attempt.',
                    'options' => [
                        'title' => 'Identity Spoof Test',
                        'user_id' =>
                        '019f62f3-f5b5-7216-9578-0af9cb3b5b99',
                    ],
                ],
            );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'options.user_id',
            ]);

        Bus::assertNotDispatched(
            SendAsynchronousNotificationJob::class,
        );
    }

    public function test_client_cannot_supply_unsupported_notification_options(): void
    {
        Bus::fake();

        $token = $this->issueAuthenticatedToken();

        $response = $this
            ->withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ])
            ->postJson(
                '/api/v1/core/notifications/dispatch',
                [
                    'recipient' => '089987654321',
                    'body' => 'Unsupported option attempt.',
                    'options' => [
                        'title' => 'Unsupported Option Test',
                        'gateway_token' => 'client-controlled-secret',
                    ],
                ],
            );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'options',
            ]);

        Bus::assertNotDispatched(
            SendAsynchronousNotificationJob::class,
        );
    }

    public function test_valid_notification_payload_is_still_accepted(): void
    {
        Bus::fake();

        $token = $this->issueAuthenticatedToken();

        $response = $this
            ->withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ])
            ->postJson(
                '/api/v1/core/notifications/dispatch',
                [
                    'recipient' => ' 089987654321 ',
                    'body' => ' Notification validation success. ',
                    'options' => [
                        'title' => ' Validation Success ',
                    ],
                ],
            );

        $response
            ->assertAccepted()
            ->assertJsonPath(
                'status',
                'success',
            );

        Bus::assertDispatched(
            SendAsynchronousNotificationJob::class,
            function (
                SendAsynchronousNotificationJob $job,
            ): bool {
                return $job->getTenantId()
                    === $this->tenantId;
            },
        );
    }
}
