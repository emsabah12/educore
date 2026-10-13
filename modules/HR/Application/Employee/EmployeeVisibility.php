<?php

namespace Modules\HR\Application\Employee;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Tenancy\TenantContext;

/**
 * Siapa melihat pegawai mana (PRD-001 §5.3). Saringan dijalankan di query database,
 * sebelum paginasi ([LAMA] HR-017 INV-HR-011), bukan di tampilan.
 *
 *   Pemegang izin tingkat Yayasan (role tenant-wide, lembaga kerja Seluruh Yayasan)
 *     → semua pegawai yayasan, termasuk yang belum punya penempatan.
 *   Selain itu → pegawai yang salah satu penempatan "berjalan"-nya ada di node yang
 *     boleh dilihat (PRD-000 §7.2, dibatasi lembaga kerja aktif).
 *
 * Penempatan "berjalan": masih terbuka; untuk pegawai BERHENTI, penempatan yang ditutup
 * bersamaan dengan berhentinya (supaya kepala lembaga terakhirnya tetap melihat riwayatnya).
 *
 * Query pemakai WAJIB memakai alias `e` untuk tabel hr_employees.
 */
final class EmployeeVisibility
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Kondisi SQL "penempatan berjalan" untuk alias penempatan $alias (dan pegawai `e`).
     *
     * Alias wajib teks tetap di kode (literal-string), bukan isian pengguna, agar aman
     * dipakai di whereRaw() tanpa risiko SQL injection.
     *
     * @param  literal-string  $alias
     * @return literal-string
     */
    public static function currentPlacementSql(string $alias): string
    {
        return "({$alias}.end_date IS NULL OR (e.status = 'BERHENTI' AND {$alias}.end_date = "
            .'(SELECT max(lp.end_date) FROM hr_employment_periods lp WHERE lp.employee_id = e.id)))';
    }

    /**
     * Apakah pengguna melihat seluruh pegawai yayasan dengan $permission?
     */
    public function seesWholeTenant(string $permission): bool
    {
        return $this->authorization->inspectTarget($permission, null, null)->allowed();
    }

    /**
     * Saring query pegawai (alias `e`) ke yayasan aktif dan cakupan $permission.
     */
    public function apply(Builder $query, string $permission): void
    {
        $query->where('e.tenant_id', $this->tenantContext->require()->tenantId);

        if ($this->seesWholeTenant($permission)) {
            return;
        }

        $nodeIds = $this->authorization->visibleNodeIds($permission);

        if ($nodeIds === []) {
            $query->whereRaw('false');

            return;
        }

        $query->whereExists(function (Builder $placements) use ($nodeIds): void {
            $placements->select(DB::raw(1))
                ->from('hr_placements as vp')
                ->whereColumn('vp.employee_id', 'e.id')
                ->whereIn('vp.organization_id', $nodeIds)
                ->whereRaw(self::currentPlacementSql('vp'));
        });
    }

    /**
     * Apakah pegawai $employeeId terlihat dengan $permission? ID asal-asalan → false.
     */
    public function canSee(string $employeeId, string $permission): bool
    {
        if (! Str::isUuid($employeeId)) {
            return false;
        }

        $query = DB::table('hr_employees as e')->where('e.id', $employeeId);
        $this->apply($query, $permission);

        return $query->exists();
    }
}
