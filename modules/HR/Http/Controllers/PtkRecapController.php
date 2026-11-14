<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Application\Authorization\CoreAccess;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Ptk\PtkRecap;
use Modules\HR\Application\Ptk\PtkRecapExport;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Rekap PTK Simpatika (PRD-001 §21.2, HR-10b). Pemilik `hr.employees.view`; baris mengikuti cakupan lihat.
 * Unduhan memuat data pribadi (OD-HR-25) sehingga dicatat di audit (tanpa isi).
 */
final class PtkRecapController
{
    public function index(Request $request, PtkRecap $recap, AuthorizationService $authorization): Response
    {
        Gate::authorize(HrAccess::EMPLOYEES_VIEW);
        $organization = $this->organization($request->query('lembaga'));

        return Inertia::render('hr/employees/ptk-recap', [
            'recap' => $recap->build($organization?->id),
            'checked' => PtkRecap::CHECKED,
            'organization' => $organization?->id,
            'organizations' => Organization::query()->where('status', OrganizationStatus::Active->value)->orderBy('code')->get()
                ->map(fn (Organization $item): array => ['value' => $item->id, 'label' => "{$item->name} ({$item->code})"])->values()->all(),
            // Tautan ke halaman Lembaga untuk melengkapi data resmi (Admin Yayasan).
            'canEditOrganizations' => $authorization->inspectTarget(CoreAccess::ORGANIZATIONS_MANAGE, null, null)->allowed(),
            'today' => BusinessClock::todayString(),
        ]);
    }

    public function export(Request $request, PtkRecap $recap, PtkRecapExport $export, AuditLogger $audit): BinaryFileResponse
    {
        Gate::authorize(HrAccess::EMPLOYEES_VIEW);
        $organization = $this->organization($request->query('lembaga'));
        $result = $recap->export($organization?->id);
        $path = tempnam(sys_get_temp_dir(), 'educore-ptk-');

        if ($path === false) {
            abort(500, 'File unduhan tidak bisa dibuat.');
        }

        $export->write(
            $result['rows'],
            $result['missing'],
            'Rekap PTK'.($organization === null ? '' : " — {$organization->name}").' per '.BusinessClock::todayString(),
            $result['truncated'],
            $path,
        );

        $audit->record(
            HrAuditAction::PTK_EXPORTED,
            HrAuditAction::SUBJECT_EMPLOYEE,
            null,
            ['lembaga' => $organization === null ? 'Semua yang terlihat' : $organization->name, 'baris' => count($result['rows']), 'data_pribadi' => $result['personal_count']],
        );

        return response()
            ->download($path, 'rekap-ptk-'.BusinessClock::todayString().'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
            ->deleteFileAfterSend();
    }

    private function organization(mixed $requested): ?Organization
    {
        if (! is_string($requested) || ! Str::isUuid($requested)) {
            return null;
        }

        $organization = Organization::query()->find($requested);

        return $organization instanceof Organization ? $organization : null;
    }
}
