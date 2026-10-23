<?php

namespace Modules\HR\Application\Employee;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Modules\Core\Application\Person\PersonNik;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Domain\Employee\EducationLevel;
use Modules\HR\Domain\Employee\EmployeeStatus;
use Modules\HR\Domain\Employee\EndReason;

/**
 * Isi halaman detail pegawai (PRD-001 §6). Pemanggil WAJIB sudah memastikan pegawai ini
 * terlihat oleh pengguna (EmployeeVisibility). Data pribadi hanya diisi bila $withPersonal.
 */
final class EmployeeProfile
{
    public function __construct(
        private readonly PersonNik $personNik,
    ) {}

    /**
     * @return array<string, mixed>|null null bila pegawai tidak ada di yayasan ini
     */
    public function load(string $tenantId, string $employeeId, bool $withPersonal): ?array
    {
        $row = DB::table('hr_employees as e')
            ->join('memberships as m', 'm.id', '=', 'e.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->leftJoin('users as u', 'u.person_id', '=', 'p.id')
            ->where('e.tenant_id', $tenantId)
            ->where('e.id', $employeeId)
            ->first([
                'e.id', 'e.employee_number', 'e.nuptk', 'e.education_level', 'e.education_major', 'e.status',
                'p.id as person_id', 'p.name', 'p.gender', 'p.birth_place', 'p.birth_date', 'p.phone', 'p.address',
                'u.id as user_id',
            ]);

        if ($row === null) {
            return null;
        }

        $status = EmployeeStatus::from((string) $row->status);
        $gender = $row->gender === null ? null : Gender::from((string) $row->gender);
        $education = $row->education_level === null ? null : EducationLevel::from((string) $row->education_level);
        $periods = $this->periods($employeeId);
        $placements = $this->placements($employeeId, $status, $periods);

        $spans = [];

        foreach ($periods as $period) {
            $spans[] = [
                'start' => CarbonImmutable::parse($period['start_date']),
                'end' => $period['end_date'] === null ? null : CarbonImmutable::parse($period['end_date']),
            ];
        }

        $service = ServiceLength::of($spans, BusinessClock::today());
        $nik = $withPersonal ? $this->personNik->valueOf((string) $row->person_id) : null;
        $birthDate = $row->birth_date === null ? null : substr((string) $row->birth_date, 0, 10);

        return [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'gender' => $gender?->label(),
            'status' => $status->value,
            'status_label' => $status->label(),
            'has_account' => $row->user_id !== null,
            'employee_number' => $row->employee_number === null ? null : (string) $row->employee_number,
            'nuptk' => $row->nuptk === null ? null : (string) $row->nuptk,
            'education' => $education?->label(),
            'education_major' => $row->education_major === null ? null : (string) $row->education_major,
            'service_length' => $service['label'],
            // Data pribadi (kelas CONFIDENTIAL, PRD-001 §4.5): null bila tidak berhak melihat.
            'personal' => $withPersonal ? [
                // NIK selalu tersamar di halaman detail; angka lengkap hanya di form Ubah profil (pengelola).
                'nik' => $nik === null ? null : PersonNik::mask($nik),
                'nik_warning' => $nik === null ? null : PersonNik::mismatch($nik, $birthDate, $gender),
                'birth_place' => $row->birth_place === null ? null : (string) $row->birth_place,
                'birth_date' => $row->birth_date === null ? null : (string) $row->birth_date,
                'phone' => $row->phone === null ? null : (string) $row->phone,
                'address' => $row->address === null ? null : (string) $row->address,
            ] : null,
            'periods' => array_reverse($periods),
            'placements' => $placements,
        ];
    }

    /**
     * Nilai mentah untuk form aksi (HR-1d): isian "Ubah profil" dan status kepegawaian berjalan.
     * Hanya dipanggil untuk pengelola (Admin SDM / Admin Yayasan).
     *
     * @return array<string, string|null>
     */
    public function editValues(string $tenantId, string $employeeId): array
    {
        $row = DB::table('hr_employees as e')
            ->join('memberships as m', 'm.id', '=', 'e.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->leftJoin('hr_employment_periods as ep', function (JoinClause $join): void {
                $join->on('ep.employee_id', '=', 'e.id')->whereNull('ep.end_date');
            })
            ->where('e.tenant_id', $tenantId)
            ->where('e.id', $employeeId)
            ->first([
                'p.id as person_id', 'p.name', 'p.gender', 'p.birth_place', 'p.birth_date', 'p.phone', 'p.address',
                'e.employee_number', 'e.nuptk', 'e.education_level', 'e.education_major',
                'ep.employment_type_id', 'ep.classification_id', 'ep.start_date as period_start',
            ]);

        if ($row === null) {
            return [];
        }

        $values = [];

        foreach ((array) $row as $key => $value) {
            if ($key !== 'person_id') {
                $values[(string) $key] = $value === null ? null : (string) $value;
            }
        }

        $values['nik'] = $this->personNik->valueOf((string) $row->person_id);

        return $values;
    }

    /**
     * Periode kepegawaian, urut dari yang paling lama.
     *
     * @return list<array{id: string, employment_type: string, classification: string|null, start_date: string, end_date: string|null, end_reason: string|null, end_note: string|null}>
     */
    private function periods(string $employeeId): array
    {
        $rows = DB::table('hr_employment_periods as ep')
            ->join('hr_employment_types as t', 't.id', '=', 'ep.employment_type_id')
            ->leftJoin('hr_employment_classifications as c', 'c.id', '=', 'ep.classification_id')
            ->where('ep.employee_id', $employeeId)
            ->orderBy('ep.start_date')
            ->get(['ep.id', 'ep.start_date', 'ep.end_date', 'ep.end_reason', 'ep.end_note', 't.name as type_name', 'c.name as classification_name']);

        $periods = [];

        foreach ($rows as $row) {
            $periods[] = [
                'id' => (string) $row->id,
                'employment_type' => (string) $row->type_name,
                'classification' => $row->classification_name === null ? null : (string) $row->classification_name,
                'start_date' => (string) $row->start_date,
                'end_date' => $row->end_date === null ? null : (string) $row->end_date,
                'end_reason' => $row->end_reason === null ? null : EndReason::from((string) $row->end_reason)->label(),
                'end_note' => $row->end_note === null ? null : (string) $row->end_note,
            ];
        }

        return $periods;
    }

    /**
     * Semua penempatan pegawai ini, dipisah "berjalan" dan "riwayat".
     *
     * [ASUMSI] Penempatan di lembaga lain ikut tampil (mis. Kepala Unit 1 melihat bahwa guru
     * MTs Unit 1 juga mengajar di MDA Unit 2), karena itu data milik pegawai yang memang terlihat.
     *
     * @param  list<array{id: string, employment_type: string, classification: string|null, start_date: string, end_date: string|null, end_reason: string|null, end_note: string|null}>  $periods
     * @return array{current: list<array<string, mixed>>, history: list<array<string, mixed>>}
     */
    private function placements(string $employeeId, EmployeeStatus $status, array $periods): array
    {
        $lastPeriodEnd = $periods === [] ? null : $periods[count($periods) - 1]['end_date'];

        $rows = DB::table('hr_placements as pl')
            ->join('organizations as o', 'o.id', '=', 'pl.organization_id')
            ->join('hr_positions as pos', 'pos.id', '=', 'pl.position_id')
            ->where('pl.employee_id', $employeeId)
            ->orderByDesc('pl.is_primary')
            ->orderByDesc('pl.start_date')
            ->get(['pl.id', 'pl.is_primary', 'pl.start_date', 'pl.end_date', 'o.name as organization_name', 'o.code as organization_code', 'pos.name as position_name']);

        $current = [];
        $history = [];

        foreach ($rows as $row) {
            $endDate = $row->end_date === null ? null : (string) $row->end_date;
            $isCurrent = $endDate === null
                || ($status === EmployeeStatus::Ended && $lastPeriodEnd !== null && $endDate === $lastPeriodEnd);

            $placement = [
                'id' => (string) $row->id,
                'organization' => (string) $row->organization_name,
                'organization_code' => (string) $row->organization_code,
                'position' => (string) $row->position_name,
                'is_primary' => (bool) $row->is_primary,
                'start_date' => (string) $row->start_date,
                'end_date' => $endDate,
            ];

            if ($isCurrent) {
                $current[] = $placement;
            } else {
                $history[] = $placement;
            }
        }

        return ['current' => $current, 'history' => $history];
    }
}
