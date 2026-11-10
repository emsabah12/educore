<?php

namespace Modules\Academic\Application\Subject;

use Illuminate\Support\Facades\DB;
use Modules\Academic\Domain\Subject\Subject;

/**
 * Daftar mata pelajaran per lembaga (PRD-002 §16 AC-3a). Pemanggil WAJIB sudah memastikan
 * lembaganya terlihat oleh pengguna.
 *
 * @phpstan-type SubjectRow array{id: string, name: string, code: string|null, grade_min: int|null, grade_max: int|null, grades: string, is_active: bool, deletable: bool, grading_aspects: list<string>|null, group_id: string|null, group: string|null, sort_order: int|null, grade_type: string}
 * @phpstan-type GroupRow array{id: string, name: string, sort_order: int, counts_in_totals: bool, subject_count: int}
 */
final class SubjectDirectory
{
    /**
     * Urut kelompok lalu urutan & nama mapel (AC-8b); aktif dan nonaktif sekaligus (nonaktif ditandai
     * di halaman).
     *
     * @return list<SubjectRow>
     */
    public function list(string $tenantId, string $organizationId): array
    {
        $ids = SubjectOrder::apply(
            DB::table('ac_subjects as s')->where('s.tenant_id', $tenantId)->where('s.organization_id', $organizationId),
        )->orderBy('s.id')->pluck('s.id')->map(fn (mixed $id): string => (string) $id)->all();

        $byId = Subject::query()->withoutGlobalScope('tenant')->whereKey($ids)->get()->keyBy('id');
        $subjects = [];

        foreach ($ids as $id) {
            $subject = $byId->get($id);

            if ($subject instanceof Subject) {
                $subjects[] = $subject;
            }
        }

        $groups = DB::table('ac_subject_groups')->where('organization_id', $organizationId)->pluck('name', 'id')->all();

        // Mapel yang sudah dipakai di kelas tidak bisa dihapus (hanya dinonaktifkan).
        $used = DB::table('ac_class_subjects')
            ->whereIn('subject_id', $ids)
            ->distinct()
            ->pluck('subject_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

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
                'deletable' => ! in_array($subject->id, $used, true),
                'grading_aspects' => $subject->grading_aspects,
                'group_id' => $subject->group_id,
                'group' => $subject->group_id === null ? null : (string) ($groups[$subject->group_id] ?? ''),
                'sort_order' => $subject->sort_order,
                'grade_type' => $subject->grade_type,
            ];
        }

        return $rows;
    }

    /**
     * Kelompok mapel lembaga, urut urutan lalu nama, beserta jumlah mapelnya.
     *
     * @return list<GroupRow>
     */
    public function groups(string $tenantId, string $organizationId): array
    {
        return array_values(DB::table('ac_subject_groups as g')
            ->where('g.tenant_id', $tenantId)
            ->where('g.organization_id', $organizationId)
            ->orderBy('g.sort_order')
            ->orderByRaw('lower(g.name)')
            ->get(['g.id', 'g.name', 'g.sort_order', 'g.counts_in_totals'])
            ->map(fn (object $group): array => [
                'id' => (string) $group->id,
                'name' => (string) $group->name,
                'sort_order' => (int) $group->sort_order,
                'counts_in_totals' => (bool) $group->counts_in_totals,
                'subject_count' => DB::table('ac_subjects')->where('group_id', $group->id)->count(),
            ])
            ->all());
    }
}
