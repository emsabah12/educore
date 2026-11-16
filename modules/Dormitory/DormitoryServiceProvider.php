<?php

namespace Modules\Dormitory;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Query\Builder;
use Inertia\Inertia;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Authorization\AccessCatalog;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Application\Dashboard\DashboardCards;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\ModuleServiceProvider;
use Modules\Dormitory\Application\Audit\DormitoryAuditAction;
use Modules\Dormitory\Application\Authorization\DormitoryAccess;
use Modules\Dormitory\Application\Facility\FacilityDirectory;
use Modules\Dormitory\Application\Mutation\MutationDirectory;
use Modules\Dormitory\Application\Placement\PlacementDormitoryResidents;
use Modules\Dormitory\Application\Placement\ResidentReview;
use Modules\Dormitory\Application\Supervision\SupervisorDirectory;
use Modules\Dormitory\Console\PrunePlacementImportsCommand;
use Modules\Dormitory\Contracts\DormitoryResidents;

/**
 * Modul Dormitory (Asrama), spesifikasi: PRD-008.
 *
 * Aturan dependency: Dormitory → Core, Academic & HR (hanya kontrak publik). Modul lain hanya boleh memakai
 * kontrak publik Dormitory (`Modules\Dormitory\Contracts`, mis. Finance untuk biaya asrama, PRD-003 §20). Provider ini di-boot SETELAH Finance (bootstrap/providers.php) karena role bawaan Core diperluas di sini.
 */
class DormitoryServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        // Santri penghuni asrama untuk Finance (biaya asrama, PRD-003 §20).
        $this->app->singleton(DormitoryResidents::class, PlacementDormitoryResidents::class);
    }

    public function boot(): void
    {
        parent::boot();

        DormitoryAccess::register($this->app->make(AccessCatalog::class));
        AuditAction::registerLabels(DormitoryAuditAction::labels());

        // Menu Untuk saya → Kamar saya (DM-3): hanya bagi pegawai aktif yang punya penugasan wali kamar aktif.
        Inertia::share('dormitory', fn (): array => ['myRooms' => $this->hasMyRooms()]);

        if ($this->app->runningInConsole()) {
            $this->commands([PrunePlacementImportsCommand::class]);
        }

        // File impor penempatan sementara tidak boleh tertinggal lebih dari 24 jam (DM-5b).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('dormitory:prune-imports')->hourly()->withoutOverlapping();
        });

        // Kartu Beranda penyetuju mutasi (DM-4, PRD-008 §18.3).
        $this->app->make(DashboardCards::class)
            ->register('dormitory.mutations_pending', fn (): ?array => $this->mutationCard(false))
            ->register('dormitory.emergency_unreviewed', fn (): ?array => $this->mutationCard(true))
            // Pengurus (DM-5a, PRD-008 §10, §19.1).
            ->register('dormitory.residents_review', fn (): ?array => $this->residentsCard(false))
            ->register('dormitory.rooms_over_capacity', fn (): ?array => $this->residentsCard(true));
    }

    /**
     * @return array{key: string, title: string, value: int, description: string, href: string, tone: 'default'|'warning'|'danger'}|null
     */
    private function residentsCard(bool $overCapacity): ?array
    {
        $context = $this->app->make(TenantContext::class)->get();

        if ($context === null) {
            return null;
        }

        $nodes = $this->app->make(AuthorizationService::class)->visibleNodeIds(DormitoryAccess::RESIDENTS_MANAGE);

        if ($nodes === []) {
            return null;
        }

        if ($overCapacity) {
            $count = count(array_filter(
                $this->app->make(FacilityDirectory::class)->roomsMatching(fn (Builder $query): Builder => $query
                    ->where('r.tenant_id', $context->tenantId)
                    ->whereIn('r.organization_id', $nodes)
                    ->where('r.is_active', true)),
                fn (array $room): bool => $room['over_capacity'],
            ));

            return [
                'key' => 'dormitory.rooms_over_capacity',
                'title' => 'Kamar kelebihan muatan',
                'value' => $count,
                'description' => $count === 0 ? 'Tidak ada kamar yang kelebihan muatan.' : 'Penghuni melebihi kapasitas atau memegang bed/loker rusak. Perbaiki, ganti bed/loker, atau mutasi.',
                'href' => '/asrama',
                'tone' => $count > 0 ? 'warning' : 'default',
            ];
        }

        $count = count($this->app->make(ResidentReview::class)->flaggedIn($context->tenantId, $nodes));

        return [
            'key' => 'dormitory.residents_review',
            'title' => 'Penghuni perlu ditinjau',
            'value' => $count,
            'description' => $count === 0 ? 'Semua penghuni masih terdaftar di lembaganya.' : 'Santri yang sudah tidak terdaftar di lembaga milik asramanya. Check-out bila sudah keluar.',
            'href' => '/asrama/penghuni?tinjau=1',
            'tone' => $count > 0 ? 'warning' : 'default',
        ];
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
