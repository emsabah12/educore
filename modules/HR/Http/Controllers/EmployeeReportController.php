<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Employee\EmployeeDirectory;
use Modules\HR\Application\Employee\EmployeeExport;
use Modules\HR\Application\Employee\EmployeeFilters;
use Modules\HR\Application\Employee\EmployeeReport;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Laporan pegawai & ekspor daftar pegawai (PRD-001 §17, HR-6a). Pemilik `hr.employees.view`; angka &
 * baris mengikuti cakupan lihat masing-masing (§5.3). Tanpa izin di mana pun → 403.
 */
final class EmployeeReportController
{
    public function index(Request $request, EmployeeReport $report): Response
    {
        Gate::authorize(HrAccess::EMPLOYEES_VIEW);
        $organization = $this->organization($request->query('lembaga'));

        return Inertia::render('hr/employees/report', [
            'report' => $report->build($organization?->id),
            'titles' => EmployeeReport::GROUP_TITLES,
            'organization' => $organization?->id,
            'organizations' => Organization::query()->where('status', OrganizationStatus::Active->value)->orderBy('code')->get()
                ->map(fn (Organization $item): array => ['value' => $item->id, 'label' => "{$item->name} ({$item->code})"])->values()->all(),
            'today' => BusinessClock::todayString(),
        ]);
    }

    public function exportReport(Request $request, EmployeeReport $report, EmployeeExport $export): BinaryFileResponse
    {
        Gate::authorize(HrAccess::EMPLOYEES_VIEW);
        $organization = $this->organization($request->query('lembaga'));
        $path = $this->tempPath();

        $export->writeReport(
            $report->build($organization?->id),
            'Laporan pegawai'.($organization === null ? '' : " — {$organization->name}").' per '.BusinessClock::todayString(),
            $path,
        );

        return $this->download($path, 'laporan-pegawai-'.BusinessClock::todayString().'.xlsx');
    }

    /**
     * Daftar pegawai sesuai saringan halaman Pegawai. Dicatat di audit (bisa memuat data pribadi).
     */
    public function exportList(Request $request, EmployeeDirectory $directory, EmployeeExport $export, AuditLogger $audit): BinaryFileResponse
    {
        Gate::authorize(HrAccess::EMPLOYEES_VIEW);
        $filters = EmployeeFilters::fromArray($request->query->all());
        $result = $directory->export($filters);
        $path = $this->tempPath();

        $export->writeList($result['rows'], 'Daftar pegawai per '.BusinessClock::todayString(), $result['truncated'], $path);

        $audit->record(
            HrAuditAction::EMPLOYEES_EXPORTED,
            HrAuditAction::SUBJECT_EMPLOYEE,
            null,
            ['saringan' => array_filter($filters->toArray(), fn (string $value): bool => $value !== ''), 'baris' => count($result['rows']), 'data_pribadi' => $result['personal_count']],
        );

        return $this->download($path, 'daftar-pegawai-'.BusinessClock::todayString().'.xlsx');
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function organization(mixed $requested): ?Organization
    {
        if (! is_string($requested) || ! Str::isUuid($requested)) {
            return null;
        }

        $organization = Organization::query()->find($requested);

        return $organization instanceof Organization ? $organization : null;
    }

    private function tempPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'educore-pegawai-');

        if ($path === false) {
            abort(500, 'File unduhan tidak bisa dibuat.');
        }

        return $path;
    }

    private function download(string $path, string $name): BinaryFileResponse
    {
        return response()
            ->download($path, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
            ->deleteFileAfterSend();
    }
}
