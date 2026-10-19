<?php

namespace Modules\Academic\Application\Student;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Academic\Application\Authorization\AcademicAccess;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Organization\OrganizationType;

/**
 * Siapa boleh melakukan aksi apa di detail siswa (PRD-002 §5.3, OD-AC-09; AC-1c-b1).
 *
 * - Data diri (milik orang, bukan milik lembaga): Operator Akademik di lembaga MANA PUN tempat
 *   siswa masih aktif, dan Admin Yayasan (izin kelola tingkat Yayasan). Wajib juga berhak melihat
 *   data pribadi, karena form menampilkan isinya.
 * - Pendaftaran: hanya di lembaga yang ada di cakupan izin kelola (dicek lewat Gate per pendaftaran).
 * - Orang tua/wali (AC-1c-b2): sama dengan data diri.
 * - Daftarkan ke lembaga lain: lembaga aktif di cakupan izin kelola yang belum punya pendaftaran
 *   terbuka untuk siswa ini.
 *
 * Pemanggil WAJIB sudah memastikan siswa terlihat (StudentVisibility); di luar itu jawabannya 404.
 */
final class StudentManageAccess
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly StudentVisibility $visibility,
    ) {}

    public function managesAnywhere(): bool
    {
        return $this->authorization->allows(AcademicAccess::STUDENTS_MANAGE);
    }

    public function canEditProfile(string $studentId): bool
    {
        if (! $this->managesAnywhere() || ! $this->visibility->canSee($studentId, AcademicAccess::STUDENTS_VIEW_PERSONAL)) {
            return false;
        }

        if ($this->visibility->seesWholeTenant(AcademicAccess::STUDENTS_MANAGE)) {
            return true;
        }

        $nodeIds = $this->authorization->visibleNodeIds(AcademicAccess::STUDENTS_MANAGE);

        return $nodeIds !== [] && DB::table('ac_enrollments')
            ->where('student_id', $studentId)
            ->whereNull('end_date')
            ->whereIn('organization_id', $nodeIds)
            ->exists();
    }

    /**
     * Orang tua/wali melekat pada siswa, bukan pada salah satu lembaganya: aturannya sama dengan
     * data diri (OD-AC-09) [OWNER, 2026-10-18].
     */
    public function canManageGuardians(string $studentId): bool
    {
        return $this->canEditProfile($studentId);
    }

    public function canManageOrganization(string $organizationId): bool
    {
        return $this->authorization->allows(AcademicAccess::STUDENTS_MANAGE, $organizationId);
    }

    /**
     * Lembaga tujuan "Daftarkan ke lembaga lain".
     *
     * @return list<array{value: string, label: string}>
     */
    public function enrollTargets(string $studentId): array
    {
        $nodeIds = $this->authorization->visibleNodeIds(AcademicAccess::STUDENTS_MANAGE);

        if ($nodeIds === []) {
            return [];
        }

        $rows = DB::table('organizations as o')
            ->whereIn('o.id', $nodeIds)
            ->where('o.type', OrganizationType::Lembaga->value)
            ->where('o.status', OrganizationStatus::Active->value)
            ->whereNotExists(function (Builder $open) use ($studentId): void {
                $open->select(DB::raw(1))
                    ->from('ac_enrollments as e')
                    ->whereColumn('e.organization_id', 'o.id')
                    ->where('e.student_id', $studentId)
                    ->whereNull('e.end_date');
            })
            ->orderBy('o.name')
            ->get(['o.id', 'o.name']);

        $options = [];

        foreach ($rows as $row) {
            $options[] = ['value' => (string) $row->id, 'label' => (string) $row->name];
        }

        return $options;
    }
}
