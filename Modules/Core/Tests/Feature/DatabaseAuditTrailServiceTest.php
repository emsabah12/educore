<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Modules\Core\Governance\Audit\Persistence\DatabaseAuditTrailService;
use Modules\Core\Identity\Models\User;
use Modules\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class DatabaseAuditTrailServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_binding_persists_canonical_audit_columns_and_sanitizes_metadata(): void
    {
        $user = User::factory()->create();

        $tenant = Tenant::query()->create([
            'name' => 'Audit Persistence Tenant',
            'subdomain' => 'audit-persistence-tenant',
            'is_active' => true,
        ]);

        $service = app(AuditTrailServiceInterface::class);

        $this->assertInstanceOf(
            DatabaseAuditTrailService::class,
            $service,
        );

        $service->log(
            eventType: 'tenant.audit.persistence.test',
            description: 'Canonical audit persistence regression.',
            tenantId: (string) $tenant->id,
            actorUserId: (string) $user->id,
            metadata: [
                'membership_id' => '019f62f3-f5b5-7216-9578-0af9cb3b5b59',
                'credentials' => [
                    'password' => 'plain-secret',
                    'access_token' => 'raw-access-token',
                ],
            ],
        );

        $record = DB::table('audit_logs')
            ->where(
                'event_type',
                'tenant.audit.persistence.test',
            )
            ->first();

        $this->assertNotNull($record);
        $this->assertSame(
            (string) $tenant->id,
            $record->tenant_id,
        );
        $this->assertSame(
            (string) $user->id,
            $record->actor_user_id,
        );

        $metadata = json_decode(
            (string) $record->metadata,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(
            '019f62f3-f5b5-7216-9578-0af9cb3b5b59',
            $metadata['membership_id'],
        );
        $this->assertSame(
            '********',
            $metadata['credentials']['password'],
        );
        $this->assertSame(
            '********',
            $metadata['credentials']['access_token'],
        );
    }

    public function test_event_type_uses_canonical_one_hundred_character_limit(): void
    {
        $service = app(AuditTrailServiceInterface::class);
        $eventType = str_repeat('a', 120);

        $service->log(
            eventType: $eventType,
            description: 'Event type length regression.',
        );

        $storedEventType = DB::table('audit_logs')
            ->value('event_type');

        $this->assertSame(
            100,
            strlen((string) $storedEventType),
        );
        $this->assertSame(
            substr($eventType, 0, 100),
            $storedEventType,
        );
    }
}
