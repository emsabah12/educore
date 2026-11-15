<?php

namespace Modules\Dormitory;

use Inertia\Inertia;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Authorization\AccessCatalog;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Application\Dashboard\DashboardCards;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\ModuleServiceProvider;
use Modules\Dormitory\Application\Audit\DormitoryAuditAction;
use Modules\Dormitory\Application\Authorization\DormitoryAccess;
use Modules\Dormitory\Application\Mutation\MutationDirectory;
use Modules\Dormitory\Application\Supervision\SupervisorDirectory;

/**
 * Modul Dormitory (Asrama), spesifikasi: PRD-008.
 *
 * Aturan dependency: Dormitory → Core, Academic & HR (hanya kontrak publik). Modul lain tidak bergantung pada
 * Dormitory. Provider ini di-boot SETELAH Finance (bootstrap/providers.php) karena role bawaan Core diperluas di sini.
 */
class DormitoryServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    public function boot(): void
    {
        parent::boot();

        DormitoryAccess::register($this->app->make(AccessCatalog::class));
        AuditAction::registerLabels(DormitoryAuditAction::labels());

        // Menu Untuk saya → Kamar saya (DM-3): hanya bagi pegawai aktif yang punya penugasan wali kamar aktif.
        Inertia::share('dormitory', fn (): array => ['myRooms' => $this->hasMyRooms()]);

        // Kartu Beranda penyetuju mutasi (DM-4, PRD-008 §18.3).
        $this->app->make(DashboardCards::class)
            ->register('dormitory.mutations_pending', fn (): ?array => $this->mutationCard(false))
            ->register('dormitory.emergency_unreviewed', fn (): ?array => $this->mutationCard(true));
    }

    /**
     * @return array{key: string, title: string, value: int, description: string, href: string, tone: 'default'|'warning'|'danger'}|null
     */
    private function mutationCard(bool $emergency): ?array
    {
        $context = $this->app->make(TenantContext::class)->get();

        if ($context === null) {
            return null;
        }

        $nodes = $this->app->make(AuthorizationService::class)->visibleNodeIds(DormitoryAccess::MUTATIONS_APPROVE);

        if ($nodes === []) {
            return null;
        }

        $counts = $this->app->make(MutationDirectory::class)->counts($context->tenantId, $nodes);

        if ($emergency) {
            return [
                'key' => 'dormitory.emergency_unreviewed',
                'title' => 'Mutasi darurat belum ditinjau',
                'value' => $counts['unreviewed'],
                'description' => $counts['unreviewed'] === 0 ? 'Semua mutasi darurat sudah ditinjau.' : 'Tinjau alasan dan kategorinya, lalu tandai Sudah ditinjau.',
                'href' => '/asrama/mutasi?tab=darurat',
                'tone' => $counts['unreviewed'] > 0 ? 'danger' : 'default',
            ];
        }

        return [
            'key' => 'dormitory.mutations_pending',
            'title' => 'Pengajuan mutasi menunggu',
            'value' => $counts['pending'],
            'description' => $counts['pending'] === 0 ? 'Tidak ada pengajuan mutasi kamar yang menunggu.' : 'Setujui atau tolak pengajuan mutasi kamar.',
            'href' => '/asrama/mutasi',
            'tone' => $counts['pending'] > 0 ? 'warning' : 'default',
        ];
    }

    private function hasMyRooms(): bool
    {
        $context = $this->app->make(TenantContext::class)->get();

        return $context !== null
            && $this->app->make(SupervisorDirectory::class)->roomIdsOf($context->tenantId, $context->membershipId) !== [];
    }
}
