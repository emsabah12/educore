<?php

namespace Modules\Academic\Application\Teaching;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Academic\Domain\Classroom\ScheduleEntry;
use Modules\Academic\Domain\Teaching\TeachingStatus;
use Modules\HR\Contracts\ActiveEmployees;
use Modules\HR\Contracts\ApprovedLeaves;

/**
 * Calon guru pengganti untuk satu pertemuan (PRD-002 §20.3, OD-AC-15).
 *
 * Calon = pegawai aktif lembaga kelas itu, selain pengampunya. Tidak tersedia bila:
 * - sedang cuti disetujui pada tanggal itu;
 * - punya jadwal mengajar sendiri yang beririsan jamnya (di lembaga mana pun, kelas aktif, tahun
 *   ajaran yang memuat tanggal itu; libur sekolah tidak diperhitungkan [ASUMSI]);
 * - sudah ditunjuk menggantikan pertemuan lain yang beririsan jamnya.
 * Urutan saran: guru piket hari itu yang tersedia, lalu yang tersedia, lalu yang tidak tersedia.
 *
 * @phpstan-type Candidate array{value: string, label: string, duty: bool, available: bool, note: string|null}
 */
final class SubstituteFinder
{
    public function __construct(
        private readonly ActiveEmployees $employees,
        private readonly ApprovedLeaves $leaves,
        private readonly DutyRoster $roster,
    ) {}

    /**
     * @param  string|null  $teacherId  pengampu pertemuan (dikecualikan)
     * @param  string|null  $exceptEntryId  pertemuan ini sendiri (catatan penggantinya tidak dihitung bentrok)
     * @return list<Candidate>
     */
    public function candidates(string $tenantId, string $organizationId, string $date, string $start, string $end, ?string $teacherId, ?string $exceptEntryId): array
    {
        $people = [];

        foreach ($this->employees->inOrganization($tenantId, $organizationId) as $employee) {
            if ($employee['id'] !== $teacherId) {
                $people[$employee['id']] = $employee['name'];
            }
        }

        if ($people === []) {
            return [];
        }

        $ids = array_map('strval', array_keys($people));
        $busy = $this->busy($tenantId, $ids, $date, $start, $end, $exceptEntryId);
        $leaves = $this->leaves->byDate($tenantId, $ids, $date, $date);
        $duty = array_flip($this->roster->onDuty($tenantId, $organizationId, $date));
        $candidates = [];

        foreach ($people as $id => $name) {
            $id = (string) $id;
            $note = isset($leaves[$id][$date]) ? 'Cuti ('.$leaves[$id][$date].')' : ($busy[$id] ?? null);

            $candidates[] = ['value' => $id, 'label' => $name, 'duty' => isset($duty[$id]), 'available' => $note === null, 'note' => $note];
        }

        usort($candidates, fn (array $a, array $b): int => [$b['available'], $b['duty'], $a['label']] <=> [$a['available'], $a['duty'], $b['label']]);

        return $candidates;
    }

    /**
     * Alasan pegawai ini tidak bisa menggantikan, atau null bila tersedia.
     */
    public function unavailableReason(string $tenantId, string $employeeId, string $date, string $start, string $end, ?string $exceptEntryId): ?string
    {
        $leave = $this->leaves->byDate($tenantId, [$employeeId], $date, $date)[$employeeId][$date] ?? null;

        if ($leave !== null) {
            return "sedang cuti ({$leave})";
        }

        return $this->busy($tenantId, [$employeeId], $date, $start, $end, $exceptEntryId)[$employeeId] ?? null;
    }

    /**
     * Pegawai yang jamnya bentrok → keterangan singkat.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, string>
     */
    private function busy(string $tenantId, array $employeeIds, string $date, string $start, string $end, ?string $exceptEntryId): array
    {
        $busy = [];

        // Jadwal mengajarnya sendiri yang beririsan.
        $teaching = DB::table('ac_schedule_entries as e')
            ->join('ac_class_subjects as cs', 'cs.id', '=', 'e.class_subject_id')
            ->join('ac_classes as c', 'c.id', '=', 'e.class_id')
            ->join('ac_academic_years as y', 'y.id', '=', 'c.academic_year_id')
            ->where('e.tenant_id', $tenantId)
            ->whereIn('cs.teacher_employee_id', $employeeIds)
            ->where('c.is_active', true)
            ->where('e.day_of_week', CarbonImmutable::parse($date)->dayOfWeekIso)
            ->where('y.start_date', '<=', $date)
            ->where('y.end_date', '>=', $date)
            ->where('e.start_time', '<', $end)
            ->where('e.end_time', '>', $start)
            ->when($exceptEntryId !== null, fn ($query) => $query->where('e.id', '!=', $exceptEntryId))
            ->orderBy('e.start_time')
            ->get(['cs.teacher_employee_id as employee_id', 'c.name as class_name', 'e.start_time', 'e.end_time']);

        foreach ($teaching as $row) {
            $busy[(string) $row->employee_id] ??= 'Mengajar '.$row->class_name.' '.self::range((string) $row->start_time, (string) $row->end_time);
        }

        // Sudah ditunjuk menggantikan pertemuan lain yang beririsan.
        $substituting = DB::table('ac_teaching_records as r')
            ->join('ac_classes as c', 'c.id', '=', 'r.class_id')
            ->where('r.tenant_id', $tenantId)
            ->whereIn('r.substitute_employee_id', $employeeIds)
            ->where('r.status', TeachingStatus::Substituted->value)
            ->where('r.date', $date)
            ->where('r.start_time', '<', $end)
            ->where('r.end_time', '>', $start)
            ->when($exceptEntryId !== null, fn ($query) => $query->where(fn ($inner) => $inner->whereNull('r.schedule_entry_id')->orWhere('r.schedule_entry_id', '!=', $exceptEntryId)))
            ->get(['r.substitute_employee_id as employee_id', 'c.name as class_name', 'r.start_time', 'r.end_time']);

        foreach ($substituting as $row) {
            $busy[(string) $row->employee_id] ??= 'Menggantikan di '.$row->class_name.' '.self::range((string) $row->start_time, (string) $row->end_time);
        }

        return $busy;
    }

    private static function range(string $start, string $end): string
    {
        return str_replace(':', '.', substr($start, 0, 5)).'–'.str_replace(':', '.', substr($end, 0, 5));
    }

    /**
     * Nama hari tanggal itu, mis. "Kamis".
     */
    public static function dayName(string $date): string
    {
        return ScheduleEntry::dayName(CarbonImmutable::parse($date)->dayOfWeekIso);
    }
}
