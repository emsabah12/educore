<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Academic\Application\Classroom\MyClasses;
use Modules\Academic\Application\Grading\ManageGrades;
use Modules\Academic\Application\Grading\ReportCards;
use Modules\Academic\Application\Guardian\ChildRecords;
use Modules\Academic\Application\Guardian\MyChildren;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;

/**
 * Anak saya (PRD-002 §21.2–21.3, AC-7a/b): orang tua/wali melihat data anaknya, hanya baca —
 * profil & kelas, presensi per bulan, nilai final & rapor per semester.
 * Akses dari tautan orang tua/wali; siswa yang bukan anaknya (atau kelas yang bukan kelasnya) → 404.
 */
final class MyChildrenController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly MyChildren $children,
    ) {}

    public function index(): Response
    {
        $context = $this->tenantContext->require();

        return Inertia::render('academic/my-children/index', [
            'children' => $this->children->list($context->tenantId, $context->membershipId),
        ]);
    }

    public function show(string $student): Response
    {
        $context = $this->tenantContext->require();
        $detail = $this->children->detail($context->tenantId, $context->membershipId, $student);

        if ($detail === null) {
            abort(404);
        }

        return Inertia::render('academic/my-children/show', [
            ...$detail,
            // Pilihan anak lain (pindah cepat antar-anak).
            'siblings' => $this->siblings(),
            'days' => MyClasses::days(),
            'today' => BusinessClock::todayString(),
        ]);
    }

    /**
     * Presensi anak per bulan (AC-7b): rekap H/S/I/A + rincian per hari/pertemuan.
     */
    public function attendance(Request $request, string $student, ChildRecords $records): Response
    {
        $name = $this->childName($student);
        $classes = $records->classes($student);
        $picked = ChildRecords::pickClass($classes, $request->query('kelas'));
        $months = [];
        $month = null;
        $attendance = null;

        if ($picked !== null) {
            $class = $this->classOf($picked['id']);
            $months = $records->months($class);
            $requested = $request->query('bulan');
            $month = is_string($requested) && in_array($requested, array_column($months, 'value'), true)
                ? $requested
                : ($months[0]['value'] ?? null);
            $attendance = $month === null ? null : $records->attendance($class, $this->enrollmentIds($student), $month);
        }

        return Inertia::render('academic/my-children/attendance', [
            'student' => ['id' => $student, 'name' => $name],
            'classes' => $this->classOptions($classes),
            'classId' => $picked['id'] ?? null,
            'months' => $months,
            'month' => $month,
            'attendance' => $attendance,
        ]);
    }

    /**
     * Nilai final per mapel per semester (AC-7b); rapor bisa dicetak bila semua mapel sudah final.
     */
    public function grades(Request $request, string $student, ChildRecords $records, ManageGrades $grades, ReportCards $reports): Response
    {
        $name = $this->childName($student);
        $classes = $records->classes($student);
        $picked = ChildRecords::pickClass($classes, $request->query('kelas'));
        $terms = [];
        $term = null;
        $card = null;
        $report = null;
        $canPrint = false;

        if ($picked !== null) {
            $class = $this->classOf($picked['id']);
            $terms = $grades->terms($class);
            $term = ManageGrades::pickTerm($terms, $request->query('semester'));

            if ($term !== null) {
                $report = $reports->build($class, $term, $picked['enrollment_id']);
                $card = $report['cards'][0] ?? null;
                // Rapor dicetak hanya bila semua mapel kelas sudah final [ASUMSI].
                $canPrint = $card !== null && $report['pending'] === [] && $report['final_subjects'] > 0;
            }
        }

        return Inertia::render('academic/my-children/grades', [
            'student' => ['id' => $student, 'name' => $name],
            'classes' => $this->classOptions($classes),
            'classId' => $picked['id'] ?? null,
            'terms' => array_map(fn (array $option): array => ['value' => $option['value'], 'label' => $option['label']], $terms),
            'term' => $term,
            'subjects' => $card['subjects'] ?? [],
            'average' => $card['average'] ?? null,
            'attendance' => $card['attendance'] ?? null,
            'attendanceKind' => $report['attendance_kind'] ?? 'hari',
            'pending' => $report['pending'] ?? [],
            'canPrint' => $canPrint,
        ]);
    }

    /**
     * Cetak rapor anak (AC-7b). Semester belum lengkap (masih ada mapel belum final) → 404.
     */
    public function reportCard(Request $request, string $student, ChildRecords $records, ManageGrades $grades, ReportCards $reports): Response
    {
        $this->childName($student);
        $picked = ChildRecords::pickClass($records->classes($student), $request->query('kelas'));

        if ($picked === null || $picked['id'] !== $request->query('kelas')) {
            abort(404);
        }

        $class = $this->classOf($picked['id']);
        $term = ManageGrades::pickTerm($grades->terms($class), $request->query('semester'));

        if ($term === null || $term['value'] !== $request->query('semester')) {
            abort(404);
        }

        $report = $reports->build($class, $term, $picked['enrollment_id']);

        if ($report['cards'] === [] || $report['pending'] !== [] || $report['final_subjects'] === 0) {
            abort(404);
        }

        $year = AcademicYear::query()->withoutGlobalScope('tenant')->whereKey($class->academic_year_id)->firstOrFail();

        return Inertia::render('print/report-cards', [
            'class' => [
                'id' => $class->id,
                'name' => $class->name,
                'organization' => (string) DB::table('organizations')->where('id', $class->organization_id)->value('name'),
                'year' => $year->name,
                'grade' => $class->grade,
            ],
            'term' => $term,
            'report' => $report,
            'today' => BusinessClock::todayString(),
            'backUrl' => "/anak-saya/{$student}/nilai?kelas={$class->id}&semester={$term['value']}",
        ]);
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function childName(string $student): string
    {
        $context = $this->tenantContext->require();
        $name = $this->children->name($context->tenantId, $context->membershipId, $student);

        if ($name === null) {
            abort(404);
        }

        return $name;
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function siblings(): array
    {
        $context = $this->tenantContext->require();

        return array_map(fn (array $child): array => ['id' => $child['id'], 'name' => $child['name']], $this->children->list($context->tenantId, $context->membershipId));
    }

    private function classOf(string $classId): SchoolClass
    {
        return SchoolClass::query()->withoutGlobalScope('tenant')->whereKey($classId)->firstOrFail();
    }

    /**
     * @return list<string>
     */
    private function enrollmentIds(string $studentId): array
    {
        $ids = [];

        foreach (DB::table('ac_enrollments')->where('student_id', $studentId)->pluck('id') as $id) {
            $ids[] = (string) $id;
        }

        return $ids;
    }

    /**
     * @param  list<array{id: string, name: string, organization: string, year: string, enrollment_id: string}>  $classes
     * @return list<array{value: string, label: string}>
     */
    private function classOptions(array $classes): array
    {
        return array_map(fn (array $class): array => [
            'value' => $class['id'],
            'label' => "{$class['name']} — {$class['organization']} ({$class['year']})",
        ], $classes);
    }
}
