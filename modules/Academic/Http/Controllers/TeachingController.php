<?php

namespace Modules\Academic\Http\Controllers;

use Carbon\CarbonImmutable;
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
use Modules\Academic\Application\Teaching\SubstituteFinder;
use Modules\Academic\Application\Teaching\TeachingAccess;
use Modules\Academic\Domain\Teaching\AbsenceReason;
use Modules\Academic\Domain\Teaching\Exceptions\TeachingException;
use Modules\Academic\Domain\Teaching\TeachingStatus;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Contracts\EmployeeLookup;

/**
 * Presensi mengajar (PRD-002 §20): per lembaga & tanggal (AC-6a), Perlu pengganti & tunjuk pengganti
 * (AC-6b), dan tombol "Sudah mengajar" dari Kelas saya.
 *
 * Pintu petugas: pemilik `academic.teaching.manage` atau guru piket hari ini; tanpa keduanya 403.
 * Pertemuan yang tidak bisa ia catat → 403.
 *
 * @phpstan-type OrganizationOption array{value: string, label: string, manages: bool}
 */
final class TeachingController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EmployeeLookup $lookup,
        private readonly TeachingAccess $access,
    ) {}

    public function index(Request $request, Lessons $lessons, DutyRoster $roster, SubstituteFinder $finder): Response
    {
        $context = $this->tenantContext->require();
        $employeeId = $this->lookup->employeeIdForMembership($context->tenantId, $context->membershipId);
        [$organizations, $organization] = $this->organization($request, $context->tenantId, $employeeId);

        $today = BusinessClock::todayString();
        $date = self::validDate($request->string('tanggal')->value()) ?? $today;
        $date = $date > $today ? $today : $date;

        // Pegawai piket yang hanya punya akses hari ini tidak bisa membuka tanggal lain.
        if (! $organization['manages'] && $date !== $today) {
            $date = $today;
        }

        $day = $lessons->forOrganization($context->tenantId, $organization['value'], $date);
        $canRecord = $this->access->canRecord($context->tenantId, $organization['value'], $date, $employeeId);

        return Inertia::render('academic/teaching/index', [
            'organizations' => self::options($organizations),
            'organization' => ['value' => $organization['value'], 'label' => $organization['label']],
            'manages' => $organization['manages'],
            'date' => $date,
            'today' => $today,
            'holiday' => $day['holiday'],
            'lessons' => $day['lessons'],
            'canRecord' => $canRecord,
            'duty' => $this->dutyNames($context->tenantId, $organization['value'], $date, $roster),
            // Calon pengganti per pertemuan (AC-6b): piket & yang tidak bentrok di atas.
            'candidates' => $canRecord ? $this->candidates($context->tenantId, $organization['value'], $day['lessons'], $finder) : [],
            'reasons' => self::reasons(),
            'noteMax' => ManageTeachingRecords::NOTE_MAX_LENGTH,
        ]);
    }

    /**
     * Perlu pengganti (AC-6b): pertemuan hari ini s.d. 7 hari ke depan yang pengampunya cuti
     * disetujui, atau yang tercatat Kosong. Guru piket: hari ini saja.
     */
    public function substitutes(Request $request, Lessons $lessons, DutyRoster $roster, SubstituteFinder $finder): Response
    {
        $context = $this->tenantContext->require();
        $employeeId = $this->lookup->employeeIdForMembership($context->tenantId, $context->membershipId);
        [$organizations, $organization] = $this->organization($request, $context->tenantId, $employeeId);

        $today = BusinessClock::today();
        $days = [];
        $last = $organization['manages'] ? ManageTeachingRecords::ASSIGN_AHEAD_DAYS : 0;

        for ($offset = 0; $offset <= $last; $offset++) {
            $date = $today->addDays($offset)->toDateString();
            $needs = array_values(array_filter(
                $lessons->forOrganization($context->tenantId, $organization['value'], $date)['lessons'],
                fn (array $lesson): bool => $lesson['entry_id'] !== null && ($lesson['leave'] !== null || ($lesson['record']['status'] ?? null) === TeachingStatus::Empty->value),
            ));

            if ($needs === []) {
                continue;
            }

            $days[] = [
                'date' => $date,
                'duty' => $this->dutyNames($context->tenantId, $organization['value'], $date, $roster),
                'lessons' => $needs,
                'candidates' => $this->candidates($context->tenantId, $organization['value'], $needs, $finder),
            ];
        }

        return Inertia::render('academic/teaching/substitutes', [
            'organizations' => self::options($organizations),
            'organization' => ['value' => $organization['value'], 'label' => $organization['label']],
            'manages' => $organization['manages'],
            'today' => $today->toDateString(),
            'aheadDays' => $last,
            'days' => $days,
            'reasons' => self::reasons(),
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
        $status = TeachingStatus::from($request->string('status')->value());
        $lesson = Str::isUuid($entry) ? $lessons->find($context->tenantId, $entry, $date) : null;
        $employeeId = $this->lookup->employeeIdForMembership($context->tenantId, $context->membershipId);
        $organizationId = $lesson === null ? null : (string) $lesson->organization_id;

        $allowed = $organizationId !== null && (
            $this->access->canRecord($context->tenantId, $organizationId, $date, $employeeId)
            || ($status === TeachingStatus::Substituted && $this->access->canAssign($context->tenantId, $organizationId, $date, $employeeId))
        );

        if (! $allowed) {
            abort(403);
        }

        $reason = $request->string('absence_reason')->value();

        try {
            $records->record(
                $context->tenantId,
                $entry,
                $date,
                $status,
                $request->string('substitute_employee_id')->value() ?: null,
                $reason === '' ? null : AbsenceReason::from($reason),
                $request->input('note') === null ? null : $request->string('note')->value(),
                $context->membershipId,
            );
        } catch (TeachingException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $status === TeachingStatus::Substituted ? 'Guru pengganti dicatat.' : 'Presensi mengajar disimpan.']);

        return back();
    }

    /**
     * Batalkan penunjukan pengganti (hari ini atau yang akan datang).
     */
    public function clearSubstitute(Request $request, string $entry, Lessons $lessons, ManageTeachingRecords $records): RedirectResponse
    {
        $context = $this->tenantContext->require();
        $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        $date = $request->string('date')->value();
        $lesson = Str::isUuid($entry) ? $lessons->find($context->tenantId, $entry, $date) : null;
        $employeeId = $this->lookup->employeeIdForMembership($context->tenantId, $context->membershipId);

        if ($lesson === null || ! $this->access->canAssign($context->tenantId, (string) $lesson->organization_id, $date, $employeeId)) {
            abort(403);
        }

        try {
            $records->clearSubstitute($context->tenantId, $entry, $date);
        } catch (TeachingException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Penunjukan guru pengganti dibatalkan.']);

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

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * Lembaga yang bisa dibuka & lembaga terpilih (?lembaga=). Tanpa lembaga 403; lembaga lain 404.
     *
     * @return array{0: non-empty-list<OrganizationOption>, 1: OrganizationOption}
     */
    private function organization(Request $request, string $tenantId, ?string $employeeId): array
    {
        $organizations = $this->access->organizations($tenantId, $employeeId);

        if ($organizations === []) {
            abort(403);
        }

        $requested = $request->string('lembaga')->value();

        if ($requested === '') {
            return [$organizations, $organizations[0]];
        }

        foreach ($organizations as $option) {
            if ($option['value'] === $requested) {
                return [$organizations, $option];
            }
        }

        abort(404);
    }

    /**
     * @param  list<OrganizationOption>  $organizations
     * @return list<array{value: string, label: string}>
     */
    private static function options(array $organizations): array
    {
        return array_map(fn (array $option): array => ['value' => $option['value'], 'label' => $option['label']], $organizations);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private static function reasons(): array
    {
        $reasons = [];

        foreach (AbsenceReason::cases() as $reason) {
            $reasons[] = ['value' => $reason->value, 'label' => $reason->label()];
        }

        return $reasons;
    }

    /**
     * @return list<string>
     */
    private function dutyNames(string $tenantId, string $organizationId, string $date, DutyRoster $roster): array
    {
        $ids = $roster->onDuty($tenantId, $organizationId, $date);
        $names = [];

        foreach ($roster->namesOf($tenantId, $ids) as $name) {
            $names[] = $name;
        }

        sort($names);

        return $names;
    }

    /**
     * @param  list<array{key: string, entry_id: string|null, date: string, start: string, end: string, teacher: array{id: string, name: string}|null}>  $lessons
     * @return array<string, list<array{value: string, label: string, duty: bool, available: bool, note: string|null}>>
     */
    private function candidates(string $tenantId, string $organizationId, array $lessons, SubstituteFinder $finder): array
    {
        $result = [];

        foreach ($lessons as $lesson) {
            if ($lesson['entry_id'] !== null) {
                $result[$lesson['key']] = $finder->candidates($tenantId, $organizationId, $lesson['date'], $lesson['start'], $lesson['end'], $lesson['teacher']['id'] ?? null, $lesson['entry_id']);
            }
        }

        return $result;
    }

    private static function validDate(string $value): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof CarbonImmutable && $date->toDateString() === $value ? $value : null;
    }
}
