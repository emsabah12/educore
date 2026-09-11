<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Console;

use Illuminate\Console\Command;
use Modules\Core\Organization\Models\Organization;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Services\TenantActivationService;
use Throwable;

/**
 * Jalur backfill SATU KALI untuk tenant yang sudah ada sebelum
 * TenantActivationService dihubungkan ke provisioning tenant baru
 * (lihat catatan arsitektur di TenantActivationService) — tenant
 * baru sudah otomatis ter-cover lewat TenantProvisioningService,
 * command ini murni untuk mengejar ketertinggalan tenant lama.
 *
 * Aman dijalankan berulang kali (idempotent, mewarisi jaminan yang
 * sama dari TenantActivationService::activate()) — tenant yang
 * sudah punya Organization apa pun otomatis dilewati, bukan cuma
 * saat command ini pertama kali dijalankan.
 */
final class TenantBackfillDefaultOrganizationCommand extends Command
{
    protected $signature = 'core:tenant-backfill-default-organization
                            {--dry-run : Tampilkan tenant yang akan diproses tanpa benar-benar membuat apa pun}';

    protected $description =
    'Backfill Organization default + assign admin untuk tenant aktif yang belum pernah punya Organization sama sekali';

    public function handle(
        TenantActivationService $tenantActivationService,
    ): int {
        $dryRun = (bool) $this->option(
            'dry-run',
        );

        $totalTenantCount = Tenant::query()->count();

        $activeTenantIds = Tenant::query()
            ->where('is_active', true)
            ->orderBy('created_at')
            ->pluck('id')
            ->map(
                fn($id): string => (string) $id,
            );

        $inactiveTenantCount = $totalTenantCount - $activeTenantIds->count();

        if ($activeTenantIds->isEmpty()) {
            $this->info(
                'Tidak ada tenant aktif ditemukan. Tidak ada yang diproses.',
            );

            return Command::SUCCESS;
        }

        $this->info(
            sprintf(
                '%sMemproses %d tenant aktif...',
                $dryRun ? '[DRY RUN] ' : '',
                $activeTenantIds->count(),
            ),
        );

        if ($inactiveTenantCount > 0) {
            $this->line(
                sprintf(
                    '(%d tenant non-aktif dilewati dari pemrosesan — aktifkan dulu lalu jalankan ulang command ini kalau perlu.)',
                    $inactiveTenantCount,
                ),
            );
        }

        $createdCount = 0;
        $skippedCount = 0;
        $failedCount = 0;
        $rows = [];

        foreach ($activeTenantIds as $tenantId) {
            if ($dryRun) {
                $alreadyOnboarded = Organization::query()
                    ->where('tenant_id', $tenantId)
                    ->exists();

                if ($alreadyOnboarded) {
                    $skippedCount++;
                    $rows[] = [$tenantId, 'DILEWATI (sudah punya Organization)'];
                } else {
                    $createdCount++;
                    $rows[] = [$tenantId, 'AKAN DIBUAT'];
                }

                continue;
            }

            try {
                $organization = $tenantActivationService->activate(
                    $tenantId,
                );
            } catch (Throwable $exception) {
                report($exception);

                $failedCount++;
                $rows[] = [$tenantId, 'GAGAL — lihat log aplikasi'];

                continue;
            }

            if ($organization === null) {
                $skippedCount++;
                $rows[] = [$tenantId, 'DILEWATI (sudah punya Organization)'];
            } else {
                $createdCount++;
                $rows[] = [
                    $tenantId,
                    sprintf(
                        'DIBUAT: "%s"',
                        $organization->name,
                    ),
                ];
            }
        }

        $this->newLine();

        $this->table(
            ['Tenant ID', 'Hasil'],
            $rows,
        );

        $this->newLine();

        $this->info(
            sprintf(
                '%sSelesai: %d %s, %d dilewati (sudah onboarded), %d gagal.',
                $dryRun ? '[DRY RUN] ' : '',
                $createdCount,
                $dryRun ? 'akan dibuat' : 'dibuat',
                $skippedCount,
                $failedCount,
            ),
        );

        return $failedCount > 0
            ? Command::FAILURE
            : Command::SUCCESS;
    }
}
