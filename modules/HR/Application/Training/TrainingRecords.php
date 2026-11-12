<?php

namespace Modules\HR\Application\Training;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Domain\Training\TrainingKind;
use Modules\HR\Domain\Training\TrainingStatus;

/**
 * Tampilan riwayat pelatihan (PRD-001 §19.1, HR-8a): per pegawai, daftar pengajuan menunggu verifikasi, dan rekap
 * jam per tahun (tahun dari tanggal selesai; hanya yang DITERIMA). Hak akses dicek pemanggil.
 *
 * @phpstan-type TrainingRow array{
 *     id: string,
 *     kind: string,
 *     kind_label: string,
 *     title: string,
 *     organizer: string|null,
 *     start_date: string,
 *     end_date: string,
 *     hours: int|null,
 *     certificate_number: string|null,
 *     valid_until: string|null,
 *     expired: bool,
 *     status: string,
 *     status_label: string,
 *     decision_note: string|null,
 *     file_name: string|null
 * }
 * @phpstan-type PendingRow array{id: string, employee_id: string, employee_name: string, organization: string|null, kind_label: string, title: string, organizer: string|null, start_date: string, end_date: string, hours: int|null, file_name: string|null, submitted_on: string}
 * @phpstan-type RecapRow array{employee_id: string, employee_name: string, employee_number: string|null, organization: string|null, count: int, hours: int}
 */
final class TrainingRecords
{
    /**
     * Pengajuan yang menunggu dulu, lalu tanggal selesai terbaru.
     *
     * @return list<TrainingRow>
     */
    public function forEmployee(string $tenantId, string $employeeId): array
    {
        $today = BusinessClock::todayString();
        $rows = [];

        foreach (DB::table('hr_trainings')
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->orderByRaw("status <> 'MENUNGGU'")
            ->orderByRaw("status IN ('DITOLAK', 'DIBATALKAN')")
            ->orderByDesc('end_date')
            ->orderByDesc('created_at')
            ->get() as $row) {
            $status = TrainingStatus::from((string) $row->status);
            $validUntil = $row->valid_until === null ? null : substr((string) $row->valid_until, 0, 10);

            $rows[] = [
                'id' => (string) $row->id,
                'kind' => (string) $row->kind,
                'kind_label' => TrainingKind::tryFrom((string) $row->kind)?->label() ?? (string) $row->kind,
                'title' => (string) $row->title,
                'organizer' => $row->organizer === null ? null : (string) $row->organizer,
                'start_date' => substr((string) $row->start_date, 0, 10),
                'end_date' => substr((string) $row->end_date, 0, 10),
                'hours' => $row->hours === null ? null : (int) $row->hours,
                'certificate_number' => $row->certificate_number === null ? null : (string) $row->certificate_number,
                'valid_until' => $validUntil,
                'expired' => $validUntil !== null && $validUntil < $today,
                'status' => $status->value,
                'status_label' => $status->label(),
                'decision_note' => $row->decision_note === null ? null : (string) $row->decision_note,
                'file_name' => $row->original_name === null ? null : (string) $row->original_name,
            ];
        }

        return $rows;
    }

    /**
     * Pengajuan pegawai yang menunggu verifikasi, terlama dulu.
     *
     * @return list<PendingRow>
     */
    public function pending(string $tenantId): array
    {
        $rows = [];

        foreach ($this->base($tenantId)
            ->where('t.status', TrainingStatus::Pending->value)
            ->orderBy('t.created_at')
            ->get(['t.*', 'p.name as employee_name', 'o.name as organization']) as $row) {
            $rows[] = [
                'id' => (string) $row->id,
                'employee_id' => (string) $row->employee_id,
                'employee_name' => (string) $row->employee_name,
                'organization' => $row->organization === null ? null : (string) $row->organization,
                'kind_label' => TrainingKind::tryFrom((string) $row->kind)?->label() ?? (string) $row->kind,
                'title' => (string) $row->title,
                'organizer' => $row->organizer === null ? null : (string) $row->organizer,
                'start_date' => substr((string) $row->start_date, 0, 10),
                'end_date' => substr((string) $row->end_date, 0, 10),
                'hours' => $row->hours === null ? null : (int) $row->hours,
                'file_name' => $row->original_name === null ? null : (string) $row->original_name,
                'submitted_on' => CarbonImmutable::parse((string) $row->created_at, 'UTC')->setTimezone(BusinessClock::timezone())->toDateString(),
            ];
        }

        return $rows;
    }

    /**
     * Rekap pelatihan yang diterima per pegawai untuk tahun (tanggal selesai) $year.
     *
     * @return list<RecapRow>
     */
    public function recap(string $tenantId, int $year): array
    {
        $rows = [];

        foreach ($this->base($tenantId)
            ->where('t.status', TrainingStatus::Accepted->value)
            ->whereBetween('t.end_date', ["{$year}-01-01", "{$year}-12-31"])
            ->groupBy('t.employee_id', 'p.name', 'e.employee_number', 'o.name')
            ->orderBy('p.name')
            ->get([
                't.employee_id', 'p.name as employee_name', 'e.employee_number', 'o.name as organization',
                DB::raw('count(*) AS trainings'), DB::raw('COALESCE(sum(t.hours), 0) AS hours'),
            ]) as $row) {
            $rows[] = [
                'employee_id' => (string) $row->employee_id,
                'employee_name' => (string) $row->employee_name,
                'employee_number' => $row->employee_number === null ? null : (string) $row->employee_number,
                'organization' => $row->organization === null ? null : (string) $row->organization,
                'count' => (int) $row->trainings,
                'hours' => (int) $row->hours,
            ];
        }

        return $rows;
    }

    /**
     * Tahun yang punya pelatihan diterima, ditambah tahun berjalan; terbaru dulu.
     *
     * @return list<int>
     */
    public function years(string $tenantId): array
    {
        $years = [BusinessClock::today()->year];

        foreach (DB::table('hr_trainings')
            ->where('tenant_id', $tenantId)
            ->where('status', TrainingStatus::Accepted->value)
            ->selectRaw('DISTINCT EXTRACT(YEAR FROM end_date)::int AS year')
            ->pluck('year') as $year) {
            $years[] = (int) $year;
        }

        $years = array_values(array_unique($years));
        rsort($years);

        return $years;
    }

    private function base(string $tenantId): Builder
    {
        return DB::table('hr_trainings as t')
            ->join('hr_employees as e', 'e.id', '=', 't.employee_id')
            ->join('memberships as m', 'm.id', '=', 'e.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->leftJoin('hr_placements as pl', fn ($join) => $join
                ->on('pl.employee_id', '=', 'e.id')
                ->where('pl.is_primary', true)
                ->whereNull('pl.end_date'))
            ->leftJoin('organizations as o', 'o.id', '=', 'pl.organization_id')
            ->where('t.tenant_id', $tenantId);
    }
}
