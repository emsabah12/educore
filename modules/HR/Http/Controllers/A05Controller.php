<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Employee\EmployeeVisibility;
use Modules\HR\Application\Ptk\A05Forms;
use Modules\HR\Domain\Ptk\SchoolPosition;

/**
 * Cetak formulir A05 Registrasi PTK Simpatika (PRD-001 §21.3, HR-10c-2) lewat browser.
 *
 * - `?pegawai=` satu pegawai: tidak terlihat → 404; terlihat tanpa hak data pribadi → 403.
 * - `?lembaga=` / tanpa saringan: semua pegawai aktif yang tampil di Rekap PTK; tanpa hak data pribadi dilewati.
 * - Dicatat di audit (saringan & jumlah formulir; tanpa isi).
 */
final class A05Controller
{
    public function print(Request $request, A05Forms $forms, EmployeeVisibility $visibility, AuditLogger $audit): Response
    {
        Gate::authorize(HrAccess::EMPLOYEES_VIEW);

        $employee = $request->string('pegawai')->value();
        $employeeId = Str::isUuid($employee) ? $employee : null;
        $organizationId = $request->string('lembaga')->value();
        $organization = $employeeId === null && Str::isUuid($organizationId) ? Organization::query()->find($organizationId) : null;
        $organization = $organization instanceof Organization ? $organization : null;

        if ($employeeId !== null) {
            if (! $visibility->canSee($employeeId, HrAccess::EMPLOYEES_VIEW)) {
                abort(404);
            }

            if (! $visibility->canSee($employeeId, HrAccess::EMPLOYEES_VIEW_PERSONAL)) {
                abort(403, 'Formulir A05 memuat NIK & nama ibu kandung; Anda tidak berhak melihat data pribadi pegawai ini.');
            }
        }

        $result = $forms->build($organization?->id, $employeeId);

        if ($employeeId !== null && $result['forms'] === []) {
            // Pegawai terlihat tetapi tidak aktif.
            abort(404);
        }

        $audit->record(
            HrAuditAction::A05_PRINTED,
            HrAuditAction::SUBJECT_EMPLOYEE,
            $employeeId,
            [
                'saringan' => $employeeId !== null ? 'satu pegawai' : ($organization === null ? 'Semua yang terlihat' : $organization->name),
                'formulir' => count($result['forms']),
                'dilewati' => $result['skipped'],
            ],
        );

        return Inertia::render('print/a05', [
            'forms' => $result['forms'],
            'skipped' => $result['skipped'],
            'truncated' => $result['truncated'],
            'limit' => A05Forms::LIMIT,
            'positions' => array_map(fn (SchoolPosition $case): array => ['value' => $case->value, 'label' => $case->formLabel()], SchoolPosition::cases()),
            'jenjangBoxes' => A05Forms::JENJANG_BOXES,
            'today' => BusinessClock::todayString(),
            'backUrl' => $employeeId !== null ? "/pegawai/{$employeeId}?tab=ptk" : '/pegawai/rekap-ptk'.($organization === null ? '' : "?lembaga={$organization->id}"),
            'backLabel' => $employeeId !== null ? 'Kembali ke Data PTK' : 'Kembali ke Rekap PTK',
        ]);
    }
}
