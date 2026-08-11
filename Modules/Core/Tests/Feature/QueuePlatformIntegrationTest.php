<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Modules\Core\Jobs\BaseTenantAwareJob;
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

    public function test_watchdog_captures_failed_job_and_persists_canonical_audit_metadata(): void
    {
        $payloadData = [
            'event_type' => 'sync_data_calas',
            'target_batch' => 12,
        ];

        $job = new MockCorruptedTenantJob(
            $this->tenantId,
            $this->operatorId,
            $payloadData,
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

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenantId,
            'actor_user_id' => $this->operatorId,
            'event_type' => 'queue.job.failed_permanently',
        ]);

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
        $this->assertSame(
            'sync_data_calas',
            $metadata['input_payload']['event_type'],
        );
    }
}
