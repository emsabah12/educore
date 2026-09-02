<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Modules\Core\Jobs\BaseTenantAwareJob;
use Modules\Core\Jobs\SendAsynchronousNotificationJob;
use Modules\Core\Listeners\QueueWatchdogListener;
use RuntimeException;
use Tests\TestCase;

final class MockCorruptedTenantJob extends BaseTenantAwareJob
{
    public function handle(): void
    {
        throw new RuntimeException(
            'Simulasi kegagalan interseptasi antrean platform.',
        );
    }
}

/**
 * Job yang secara eksplisit meng-override auditContext() untuk
 * membuktikan pola opt-in identifier-only (GAP-021 remediation).
 */
final class MockJobWithAuditContext extends BaseTenantAwareJob
{
    public function handle(): void
    {
        throw new RuntimeException(
            'Simulasi kegagalan job dengan audit context eksplisit.',
        );
    }

    protected function auditContext(): array
    {
        return [
            'record_id' => $this->payload['record_id'] ?? null,
        ];
    }
}

final class QueuePlatformIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private string $personId;
    private string $tenantId;
    private string $operatorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->personId = '019f62f3-f5b5-7216-9578-0af9cb3b5b53';
        $this->tenantId = '019f62f3-f5b5-7216-9578-0af9cb3b5b54';
        $this->operatorId = '019f62f3-f5b5-7216-9578-0af9cb3b5b55';

        DB::table('persons')->insert([
            'id' => $this->personId,
            'name' => 'System Operator Test',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Pesantren Pusat Antrean',
            'subdomain' => 'queue-test',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->operatorId,
            'person_id' => $this->personId,
            'email' => 'operator.queue@educore.id',
            'password' => bcrypt('secret-pass'),
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_watchdog_persists_canonical_audit_metadata_without_raw_payload_by_default(): void
    {
        // Payload sengaja berisi data yang seharusnya tidak pernah
        // bocor ke audit trail (mensimulasikan PII/data sensitif).
        $sensitivePayloadData = [
            'recipient_phone' => '081234567890',
            'message_body' => 'Rahasia isi pesan tidak boleh bocor.',
        ];

        $job = new MockCorruptedTenantJob(
            $this->tenantId,
            $this->operatorId,
            $sensitivePayloadData,
        );

        $exception = new RuntimeException(
            'Simulasi kegagalan interseptasi antrean platform.',
        );

        $mockQueueJob = $this->createMock(
            QueueJob::class,
        );
        $mockQueueJob
            ->method('getQueue')
            ->willReturn('default');

        $event = new JobFailed(
            'database',
            $mockQueueJob,
            $exception,
        );
        $event->data = [
            'commandName' => MockCorruptedTenantJob::class,
            'command' => serialize($job),
        ];

        app(QueueWatchdogListener::class)->handle($event);

        $record = DB::table('audit_logs')
            ->where('tenant_id', $this->tenantId)
            ->where(
                'event_type',
                'queue.job.failed_permanently',
            )
            ->first();

        $this->assertNotNull(
            $record,
            'Log audit gagal ditemukan di database.',
        );

        $metadata = json_decode(
            (string) $record->metadata,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(
            MockCorruptedTenantJob::class,
            $metadata['job_class'],
        );
        $this->assertSame(
            RuntimeException::class,
            $metadata['exception_class'],
        );

        // Fail-closed: job yang tidak meng-override auditContext()
        // tidak membocorkan apapun dari payload-nya.
        $this->assertSame(
            [],
            $metadata['job_context'],
        );

        $rawMetadataJson = (string) $record->metadata;

        $this->assertStringNotContainsString(
            '081234567890',
            $rawMetadataJson,
        );
        $this->assertStringNotContainsString(
            'Rahasia isi pesan',
            $rawMetadataJson,
        );
        $this->assertArrayNotHasKey(
            'input_payload',
            $metadata,
        );
    }

    public function test_watchdog_persists_only_explicitly_declared_identifier_when_job_opts_in(): void
    {
        $recordId = '019f62f3-f5b5-7216-9578-0af9cb3b5b56';

        $job = new MockJobWithAuditContext(
            $this->tenantId,
            $this->operatorId,
            [
                'record_id' => $recordId,
                // Field ini TIDAK dideklarasikan oleh auditContext()
                // job ini, sehingga harus tetap tidak muncul di audit.
                'sensitive_note' => 'Catatan rahasia yang tidak boleh terekspos.',
            ],
        );

        $exception = new RuntimeException(
            'Simulasi kegagalan job dengan audit context eksplisit.',
        );

        $mockQueueJob = $this->createMock(
            QueueJob::class,
        );
        $mockQueueJob
            ->method('getQueue')
            ->willReturn('default');

        $event = new JobFailed(
            'database',
            $mockQueueJob,
            $exception,
        );
        $event->data = [
            'commandName' => MockJobWithAuditContext::class,
            'command' => serialize($job),
        ];

        app(QueueWatchdogListener::class)->handle($event);

        $record = DB::table('audit_logs')
            ->where('tenant_id', $this->tenantId)
            ->where(
                'event_type',
                'queue.job.failed_permanently',
            )
            ->first();

        $this->assertNotNull($record);

        $metadata = json_decode(
            (string) $record->metadata,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(
            [
                'record_id' => $recordId,
            ],
            $metadata['job_context'],
        );

        $this->assertStringNotContainsString(
            'Catatan rahasia',
            (string) $record->metadata,
        );
    }

    public function test_watchdog_redacts_recipient_and_body_for_real_notification_job(): void
    {
        $job = new SendAsynchronousNotificationJob(
            $this->tenantId,
            $this->operatorId,
            [
                'recipient' => '081234567890',
                'body' => 'Kode OTP Anda adalah 123456. Jangan bagikan ke siapapun.',
                'options' => [],
            ],
        );

        $notificationId = $job->getNotificationId();

        $exception = new RuntimeException(
            'Simulasi kegagalan pengiriman notifikasi permanen.',
        );

        $mockQueueJob = $this->createMock(
            QueueJob::class,
        );
        $mockQueueJob
            ->method('getQueue')
            ->willReturn('default');

        $event = new JobFailed(
            'database',
            $mockQueueJob,
            $exception,
        );
        $event->data = [
            'commandName' => SendAsynchronousNotificationJob::class,
            'command' => serialize($job),
        ];

        app(QueueWatchdogListener::class)->handle($event);

        $record = DB::table('audit_logs')
            ->where('tenant_id', $this->tenantId)
            ->where(
                'event_type',
                'queue.job.failed_permanently',
            )
            ->first();

        $this->assertNotNull($record);

        $metadata = json_decode(
            (string) $record->metadata,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(
            [
                'notification_id' => $notificationId,
            ],
            $metadata['job_context'],
        );

        $rawMetadataJson = (string) $record->metadata;

        $this->assertStringNotContainsString(
            '081234567890',
            $rawMetadataJson,
        );
        $this->assertStringNotContainsString(
            'Kode OTP',
            $rawMetadataJson,
        );
    }
}
