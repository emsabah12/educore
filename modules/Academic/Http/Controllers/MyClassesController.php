<?php

namespace Modules\Academic\Http\Controllers;

use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Academic\Application\Classroom\ClassRosterExport;
use Modules\Academic\Application\Classroom\MyClasses;
use Modules\Academic\Application\Teaching\Lessons;
use Modules\Academic\Application\Teaching\ManageTeachingRecords;
use Modules\Academic\Application\Teaching\SubstituteTasks;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Menu "Kelas saya" (PRD-002 §16 AC-3d): kelas yang dipegang wali kelas/guru pengampu yang sedang
 * login, siswanya, dan jadwal mengajarnya. Hanya dibaca.
 *
 * Akses ditentukan oleh data penugasan mengajar, bukan permission: kelas yang tidak ia pegang → 404.
 */
final class MyClassesController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(MyClasses $myClasses, Lessons $lessons, SubstituteTasks $substituteTasks): Response
    {
        $context = $this->tenantContext->require();
        $employeeId = $myClasses->employeeId($context->tenantId, $context->membershipId);
        $classes = $employeeId === null ? [] : $myClasses->classes($context->tenantId, $employeeId);
        $today = BusinessClock::todayString();

        return Inertia::render('academic/my-classes/index', [
            'linked' => $employeeId !== null,
            'classes' => $classes,
            'schedule' => $employeeId === null ? [] : $myClasses->schedule($employeeId, array_column($classes, 'id')),
            'days' => MyClasses::days(),
            // Presensi mengajar 7 hari terakhir (PRD-002 §20.2, AC-6a).
            'teaching' => $employeeId === null ? [] : $lessons->forTeacher(
                $context->tenantId,
                $employeeId,
                BusinessClock::today()->subDays(ManageTeachingRecords::TEACHER_WINDOW_DAYS)->toDateString(),
                $today,
            ),
            // Tugas menggantikan 6 hari terakhir s.d. 7 hari ke depan (PRD-002 §20.3, AC-6b-2).
            'substitutions' => $employeeId === null ? [] : $substituteTasks->list($context->tenantId, $employeeId),
            'today' => $today,
            'now' => CarbonImmutable::now(BusinessClock::timezone())->format('H:i'),
        ]);
    }

    /**
     * Unduh daftar siswa kelas yang ia walikan (.xlsx, PRD-002 §18 P1): dengan kontak utama orang
     * tua/wali, tanpa tempat & tanggal lahir. Bukan wali kelasnya → 404.
     */
    public function export(string $class, MyClasses $myClasses, ClassRosterExport $export): BinaryFileResponse
    {
        $context = $this->tenantContext->require();
        $employeeId = $myClasses->employeeId($context->tenantId, $context->membershipId);
        $picked = $employeeId === null ? null : MyClasses::pick($myClasses->classes($context->tenantId, $employeeId), $class);

        if ($picked === null || ! $picked['homeroom']) {
            abort(404);
        }

        $target = SchoolClass::query()->withoutGlobalScope('tenant')->whereKey($picked['id'])->firstOrFail();

        return ClassMemberController::download($target, $export, false, true);
    }

    public function show(string $class, MyClasses $myClasses): Response
    {
        $context = $this->tenantContext->require();
        $employeeId = $myClasses->employeeId($context->tenantId, $context->membershipId);
        $picked = $employeeId === null ? null : MyClasses::pick($myClasses->classes($context->tenantId, $employeeId), $class);

        if ($picked === null) {
            abort(404);
        }

        return Inertia::render('academic/my-classes/show', [
            'class' => $picked,
            'students' => $myClasses->students($picked['id'], $picked['homeroom']),
        ]);
    }
}
