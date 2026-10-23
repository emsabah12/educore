<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Academic\Application\Teaching\DutyRoster;
use Modules\Academic\Application\Teaching\Lessons;
use Modules\Academic\Application\Teaching\ManageTeachingRecords;
use Modules\Academic\Application\Teaching\TeachingAccess;
use Modules\Academic\Domain\Teaching\AbsenceReason;
use Modules\Academic\Domain\Teaching\Exceptions\TeachingException;
use Modules\Academic\Domain\Teaching\TeachingStatus;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Contracts\ActiveEmployees;
use Modules\HR\Contracts\EmployeeLookup;

/**
 * Presensi mengajar per lembaga & tanggal (PRD-002 §20.2, AC-6a) dan tombol "Sudah mengajar" dari
 * Kelas saya. Pintu petugas: pemilik `academic.teaching.manage` atau guru piket hari ini; tanpa
 * keduanya 403. Pertemuan yang tidak bisa ia catat → 403.
 */
final class TeachingController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EmployeeLookup $lookup,
        private readonly TeachingAccess $access,
    ) {}

    public function index(Request $request, Lessons $lessons, DutyRoster $roster, ActiveEmployees $employees): Response
    {
        $context = $this->tenantContext->require();
        $employeeId = $this->lookup->employeeIdForMembership($context->tenantId, $context->membershipId);
        $organizations = $this->access->organizations($context->tenantId, $employeeId);

        if ($organizations === []) {
            abort(403);
        }

        $requested = $request->string('lembaga')->value();
        $organization = null;

        foreach ($organizations as $option) {
            if ($option['value'] === $requested) {
                $organization = $option;
            }
        }

        if ($organization === null) {
            if ($requested !== '') {
                abort(404);
            }

            $organization = $organizations[0];
        }

        $today = BusinessClock::todayString();
        $date = $request->string('tanggal')->value();
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)) && $date <= $today ? $date : $today;

        // Pegawai piket yang hanya punya akses hari ini tidak bisa membuka tanggal lain.
        if (! $organization['manages'] && $date !== $today) {
            $date = $today;
        }

        $day = $lessons->forOrganization($context->tenantId, $organization['value'], $date);
        $onDuty = $roster->onDuty($context->tenantId, $organization['value'], $date);
        $substitutes = [];

        foreach ($employees->inOrganization($context->tenantId, $organization['value']) as $employee) {
            $substitutes[] = ['value' => $employee['id'], 'label' => $employee['name'], 'duty' => in_array($employee['id'], $onDuty, true)];
        }

        // Guru piket hari itu di urutan teratas.
        usort($substitutes, fn (array $a, array $b): int => [$b['duty'], $a['label']] <=> [$a['duty'], $b['label']]);

        $reasons = [];

        foreach (AbsenceReason::cases() as $reason) {
            $reasons[] = ['value' => $reason->value, 'label' => $reason->label()];
        }

        return Inertia::render('academic/teaching/index', [
            'organizations' => array_map(fn (array $option): array => ['value' => $option['value'], 'label' => $option['label']], $organizations),
            'organization' => ['value' => $organization['value'], 'label' => $organization['label']],
            'manages' => $organization['manages'],
            'date' => $date,
            'today' => $today,
            'holiday' => $day['holiday'],
            'lessons' => $day['lessons'],
            'canRecord' => $this->access->canRecord($context->tenantId, $organization['value'], $date, $employeeId),
            'duty' => array_values(array_filter($substitutes, fn (array $option): bool => $option['duty'])),
            'substitutes' => $substitutes,
            'reasons' => $reasons,
            'noteMax' => ManageTeachingRecords::NOTE_MAX_LENGTH,
        ]);
    }

    public function store(Request $request, string $entry, Lessons $lessons, ManageTeachingRecords $records): RedirectResponse
    {
        $context = $this->tenantContext->require();

        $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', Rule::enum(TeachingStatus::class)],
            'substitute_employee_id' => ['nullable', 'uuid'],
            'absence_reason' => ['nullable', Rule::enum(AbsenceReason::class)],
            'note' => ['nullable', 'string', 'max:'.ManageTeachingRecords::NOTE_MAX_LENGTH],
        ], ['status.required' => 'Pilih status.'], ['substitute_employee_id' => 'guru pengganti', 'absence_reason' => 'alasan', 'note' => 'keterangan']);

        $date = $request->string('date')->value();
        $lesson = Str::isUuid($entry) ? $lessons->find($context->tenantId, $entry, $date) : null;
        $employeeId = $this->lookup->employeeIdForMembership($context->tenantId, $context->membershipId);

        if ($lesson === null || ! $this->access->canRecord($context->tenantId, (string) $lesson->organization_id, $date, $employeeId)) {
            abort(403);
        }

        $reason = $request->string('absence_reason')->value();

        try {
            $records->record(
                $context->tenantId,
                $entry,
                $date,
                TeachingStatus::from($request->string('status')->value()),
                $request->string('substitute_employee_id')->value() ?: null,
                $reason === '' ? null : AbsenceReason::from($reason),
                $request->input('note') === null ? null : $request->string('note')->value(),
                $context->membershipId,
            );
        } catch (TeachingException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Presensi mengajar disimpan.']);

        return back();
    }

    /**
     * "Sudah mengajar" dari Kelas saya. Bukan pegawai → 404.
     */
    public function markTaught(Request $request, string $entry, ManageTeachingRecords $records): RedirectResponse
    {
        $context = $this->tenantContext->require();
        $employeeId = $this->lookup->employeeIdForMembership($context->tenantId, $context->membershipId);

        if ($employeeId === null) {
            abort(404);
        }

        $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        try {
            $records->markTaught($context->tenantId, $entry, $request->string('date')->value(), $employeeId, $context->membershipId);
        } catch (TeachingException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tercatat: sudah mengajar.']);

        return back();
    }
}
