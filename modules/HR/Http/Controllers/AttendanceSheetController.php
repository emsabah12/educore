<?php

namespace Modules\HR\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Attendance\AttendanceRecap;
use Modules\HR\Application\Attendance\AttendanceRecapExport;
use Modules\HR\Application\Attendance\AttendanceSheet;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Domain\Attendance\AttendanceStatus;
use Modules\HR\Domain\Attendance\Exceptions\AttendanceException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Lembar presensi pegawai oleh petugas (PRD-001 §14.3, HR-4b): `/pegawai/presensi?lembaga=&tanggal=`,
 * dan rekap bulanan (§14.4, HR-4c): `/pegawai/presensi/rekap?lembaga=&bulan=` (+ `/unduh`).
 *
 * Pemilik `hr.attendance.manage` (Kepala Lembaga, Petugas Presensi di lembaganya; Admin SDM / Admin
 * Yayasan di semua lembaga). Tanpa izin di mana pun → 403; lembaga di luar cakupan → 404.
 *
 * @phpstan-type OrganizationOption array{value: string, label: string}
 */
final class AttendanceSheetController
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, AttendanceSheet $sheet): Response
    {
        [$organizations, $organization] = $this->organization($request->string('lembaga')->value());
        $tenantWide = $this->tenantWide();
        $today = BusinessClock::todayString();
        $earliest = AttendanceSheet::earliest($tenantWide);
        $date = $request->string('tanggal')->value();

        if (! CarbonImmutable::hasFormat($date, 'Y-m-d') || $date > $today || ($earliest !== null && $date < $earliest)) {
            $date = $today;
        }

        return Inertia::render('hr/attendance/sheet', [
            'organizations' => $organizations,
            'organization' => $organization,
            'date' => $date,
            'today' => $today,
            'earliest' => $earliest,
            'rows' => $sheet->rows($this->tenantContext->require()->tenantId, $organization['value'], $date),
            'statuses' => array_map(fn (AttendanceStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
                'times' => $status->hasTimes(),
            ], AttendanceStatus::cases()),
            'noteMax' => AttendanceSheet::NOTE_MAX_LENGTH,
            'officerDays' => AttendanceSheet::OFFICER_DAYS,
        ]);
    }

    public function store(Request $request, AttendanceSheet $sheet): RedirectResponse
    {
        [, $organization] = $this->organization($request->string('organization_id')->value());

        $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'rows' => ['required', 'array', 'max:500'],
            'rows.*.status' => ['nullable', 'string', 'max:12'],
            'rows.*.check_in' => ['nullable', 'string', 'max:5'],
            'rows.*.check_out' => ['nullable', 'string', 'max:5'],
            'rows.*.note' => ['nullable', 'string', 'max:'.AttendanceSheet::NOTE_MAX_LENGTH],
        ], [
            'rows.required' => 'Belum ada pegawai yang diisi.',
            'rows.*.note.max' => 'Keterangan maksimal '.AttendanceSheet::NOTE_MAX_LENGTH.' karakter.',
        ], ['date' => 'tanggal']);

        $input = [];

        foreach ((array) $request->input('rows', []) as $employeeId => $row) {
            $row = is_array($row) ? $row : [];
            $input[(string) $employeeId] = [
                'status' => $row['status'] ?? null,
                'check_in' => $row['check_in'] ?? null,
                'check_out' => $row['check_out'] ?? null,
                'note' => $row['note'] ?? null,
            ];
        }

        $context = $this->tenantContext->require();
        $date = $request->string('date')->value();

        try {
            $counts = $sheet->save($context->tenantId, $organization['value'], $date, $this->tenantWide(), $context->membershipId, $input);
        } catch (AttendanceException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        $message = $counts['saved'] + $counts['removed'] === 0
            ? 'Tidak ada perubahan.'
            : "Presensi {$organization['label']} tanggal {$date} disimpan: {$counts['saved']} diisi/diubah".($counts['removed'] > 0 ? ", {$counts['removed']} dikosongkan." : '.');

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect()->route('hr.attendance.index', ['lembaga' => $organization['value'], 'tanggal' => $date]);
    }

    public function recap(Request $request, AttendanceRecap $recap): Response
    {
        [$organizations, $organization] = $this->organization($request->string('lembaga')->value());
        $month = AttendanceRecap::month($request->query('bulan'));
        $data = $recap->forOrganization($this->tenantContext->require()->tenantId, $organization['value'], $month);
        [, $until] = AttendanceRecap::range($month);

        return Inertia::render('hr/attendance/recap', [
            'organizations' => $organizations,
            'organization' => $organization,
            'month' => $month,
            'maxMonth' => BusinessClock::today()->format('Y-m'),
            'label' => AttendanceRecap::monthLabel($month),
            'until' => $until,
            'rows' => $data['rows'],
            'totals' => $data['totals'],
        ]);
    }

    public function recapExport(Request $request, AttendanceRecap $recap, AttendanceRecapExport $export): BinaryFileResponse
    {
        [, $organization] = $this->organization($request->string('lembaga')->value());
        $month = AttendanceRecap::month($request->query('bulan'));
        [, $until] = AttendanceRecap::range($month);
        $code = (string) Organization::query()->whereKey($organization['value'])->value('code');
        $path = tempnam(sys_get_temp_dir(), 'educore-rekap-presensi-');

        if ($path === false) {
            abort(500, 'Rekap tidak bisa dibuat.');
        }

        $export->write(
            $recap->forOrganization($this->tenantContext->require()->tenantId, $organization['value'], $month),
            "Rekap presensi pegawai — {$organization['label']} — ".AttendanceRecap::monthLabel($month)." (s.d. {$until})",
            $path,
        );

        return response()
            ->download($path, AttendanceRecapExport::fileName($code, $month), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * Pemegang izin tingkat Yayasan (Admin SDM / Admin Yayasan di lembaga kerja Seluruh Yayasan):
     * bebas memilih tanggal (tidak di masa depan).
     */
    private function tenantWide(): bool
    {
        return $this->authorization->inspectTarget(HrAccess::ATTENDANCE_MANAGE, null, null)->allowed();
    }

    /**
     * Lembaga yang boleh dikelola, dan lembaga terpilih (kosong = yang pertama).
     *
     * @return array{0: list<OrganizationOption>, 1: OrganizationOption}
     */
    private function organization(string $requested): array
    {
        $ids = $this->authorization->visibleNodeIds(HrAccess::ATTENDANCE_MANAGE);

        if ($ids === []) {
            abort(403);
        }

        $options = [];

        $organizations = Organization::query()
            ->whereIn('id', $ids)
            ->where('status', OrganizationStatus::Active->value)
            ->orderBy('name')
            ->get();

        foreach ($organizations as $organization) {
            $options[] = ['value' => $organization->id, 'label' => "{$organization->name} ({$organization->code})"];
        }

        if ($options === []) {
            abort(403);
        }

        if ($requested === '') {
            return [$options, $options[0]];
        }

        foreach ($options as $option) {
            if ($option['value'] === $requested) {
                return [$options, $option];
            }
        }

        abort(404);
    }
}
