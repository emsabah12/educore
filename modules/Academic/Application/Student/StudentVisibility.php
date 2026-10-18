<?php

namespace Modules\Academic\Application\Student;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Tenancy\TenantContext;

/**
 * Siapa melihat siswa mana (PRD-002 §5.3). Saringan dijalankan di query database, sebelum
 * paginasi, bukan di tampilan.
 *
 *   Pemegang izin tingkat Yayasan (role tenant-wide, lembaga kerja Seluruh Yayasan)
 *     → semua siswa yayasan.
 *   Selain itu → siswa yang punya pendaftaran (terbuka ATAU sudah ditutup) di node yang boleh
 *     dilihat (PRD-000 §7.2, dibatasi lembaga kerja aktif).
 *
 * Pendaftaran yang sudah ditutup ikut dihitung: lembaga tetap melihat siswa yang lulus/keluar dari
 * lembaganya (riwayat/alumni), walau siswa itu masih aktif di lembaga lain — mis. lulus MDA tetapi
 * masih di SMP [tafsiran §5.3, 2026-10-18].
 *
 * Query pemakai WAJIB memakai alias `s` untuk tabel ac_students.
 */
final class StudentVisibility
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Apakah pengguna melihat seluruh siswa yayasan dengan $permission?
     */
    public function seesWholeTenant(string $permission): bool
    {
        return $this->authorization->inspectTarget($permission, null, null)->allowed();
    }

    /**
     * Node yang boleh dilihat; null = seluruh yayasan (tanpa batas node).
     *
     * @return list<string>|null
     */
    public function nodeIds(string $permission): ?array
    {
        return $this->seesWholeTenant($permission) ? null : $this->authorization->visibleNodeIds($permission);
    }

    /**
     * Saring query siswa (alias `s`) ke yayasan aktif dan cakupan $permission.
     */
    public function apply(Builder $query, string $permission): void
    {
        $query->where('s.tenant_id', $this->tenantContext->require()->tenantId);

        $nodeIds = $this->nodeIds($permission);

        if ($nodeIds === null) {
            return;
        }

        if ($nodeIds === []) {
            $query->whereRaw('false');

            return;
        }

        $query->whereExists(function (Builder $enrollments) use ($nodeIds): void {
            $enrollments->select(DB::raw(1))
                ->from('ac_enrollments as ve')
                ->whereColumn('ve.student_id', 's.id')
                ->whereIn('ve.organization_id', $nodeIds);
        });
    }

    /**
     * Apakah siswa $studentId terlihat dengan $permission? ID asal-asalan → false.
     */
    public function canSee(string $studentId, string $permission): bool
    {
        if (! Str::isUuid($studentId)) {
            return false;
        }

        $query = DB::table('ac_students as s')->where('s.id', $studentId);
        $this->apply($query, $permission);

        return $query->exists();
    }
}
