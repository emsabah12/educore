<?php

namespace Modules\Academic\Application\Subject;

use Modules\Academic\Domain\Subject\Subject;

/**
 * Daftar mata pelajaran per lembaga (PRD-002 §16 AC-3a). Pemanggil WAJIB sudah memastikan
 * lembaganya terlihat oleh pengguna.
 *
 * @phpstan-type SubjectRow array{id: string, name: string, code: string|null, grade_min: int|null, grade_max: int|null, grades: string, is_active: bool, deletable: bool}
 */
final class SubjectDirectory
{
    /**
     * Urut nama; aktif dan nonaktif sekaligus (nonaktif ditandai di halaman).
     *
     * @return list<SubjectRow>
     */
    public function list(string $tenantId, string $organizationId): array
    {
        $subjects = Subject::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('organization_id', $organizationId)
            ->orderByRaw('lower(name)')
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($subjects as $subject) {
            $rows[] = [
                'id' => $subject->id,
                'name' => $subject->name,
                'code' => $subject->code,
                'grade_min' => $subject->grade_min,
                'grade_max' => $subject->grade_max,
                'grades' => ManageSubjects::gradeLabel($subject->grade_min, $subject->grade_max),
                'is_active' => $subject->is_active,
                // Mapel kelas baru ada di AC-3b; sampai itu semua mapel boleh dihapus.
                'deletable' => true,
            ];
        }

        return $rows;
    }
}
