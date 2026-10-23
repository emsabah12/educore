<?php

namespace Modules\Academic\Application\Teaching;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Academic\Application\Calendar\SchoolDays;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Teaching\AbsenceReason;
use Modules\Academic\Domain\Teaching\TeachingStatus;
use Modules\HR\Contracts\ActiveEmployees;
use Modules\HR\Contracts\ApprovedLeaves;
use stdClass;

/**
 * Pertemuan terjadwal & catatan presensi mengajarnya (PRD-002 §20.1). Hanya membaca.
 *
 * Pertemuan = baris jadwal kelas aktif pada tanggal di dalam tahun ajaran kelas, hari jadwal = hari
 * tanggal itu, dan bukan libur kalender akademik. `leave` = jenis cuti disetujui pengampu pada tanggal
 * itu (kontrak HR ApprovedLeaves, AC-6b), tanda pertemuan perlu guru pengganti.
 *
 * @phpstan-type Person array{id: string, name: string}
 * @phpstan-type RecordView array{status: string, status_label: string, substitute: Person|null, reason: string|null, reason_label: string|null, note: string|null, source: string, recorded_by: string|null}
 * @phpstan-type Lesson array{key: string, entry_id: string|null, date: string, start: string, end: string, class_id: string, class_name: string, organization_id: string, organization: string, subject: string, teacher: Person|null, leave: string|null, record: RecordView|null}
 */
final class Lessons
{
    public const SOURCE_TEACHER = 'GURU';

    public const SOURCE_OFFICER = 'PETUGAS';

    public const SOURCE_AUTO = 'OTOMATIS';

    /** @var array<string, AcademicYear|null> cache tahun ajaran per request */
    private array $years = [];

    public function __construct(
        private readonly SchoolDays $schoolDays,
        private readonly ActiveEmployees $employees,
        private readonly ApprovedLeaves $leaves,
    ) {}

    /**
     * Pertemuan satu lembaga pada satu tanggal, urut jam & kelas. Catatan yang jadwalnya sudah dihapus
     * tetap tampil. $holiday = alasan libur bila semua kelas lembaga itu libur hari itu.
     *
     * @return array{holiday: string|null, lessons: list<Lesson>}
     */
    public function forOrganization(string $tenantId, string $organizationId, string $date): array
    {
        $entries = $this->entries($tenantId, $date, fn (Builder $query) => $query->where('c.organization_id', $organizationId));
        $holidays = [];
        $scheduled = [];

        foreach ($entries as $entry) {
            $reason = $this->holiday($entry, $date);

            if ($reason !== null) {
                $holidays[$reason] = true;

                continue;
            }

            $scheduled[] = $entry;
        }

        // Catatan yang baris jadwalnya sudah dihapus tetap tampil sebagai riwayat.
        $orphans = $this->recordQuery()
            ->join('ac_classes as c', 'c.id', '=', 'r.class_id')
            ->join('ac_class_subjects as cs', 'cs.id', '=', 'r.class_subject_id')
            ->join('ac_subjects as s', 's.id', '=', 'cs.subject_id')
            ->join('organizations as o', 'o.id', '=', 'r.organization_id')
            ->where('r.tenant_id', $tenantId)
            ->where('r.organization_id', $organizationId)
            ->where('r.date', $date)
            ->whereNull('r.schedule_entry_id')
            ->get(['r.*', 'p.name as recorded_by', 'c.name as class_name', 'o.name as organization', 's.name as subject'])
            ->all();

        $lessons = $this->present($tenantId, $date, $scheduled, array_values($orphans));

        return [
            'holiday' => $scheduled === [] && $holidays !== [] ? implode(', ', array_keys($holidays)) : null,
            'lessons' => $lessons,
        ];
    }

    /**
     * Pertemuan yang diampu pegawai ini di semua lembaga pada rentang tanggal (maks. beberapa
     * minggu), urut tanggal terbaru lalu jam.
     *
     * @return list<Lesson>
     */
    public function forTeacher(string $tenantId, string $employeeId, string $start, string $end): array
    {
        $lessons = [];
        $day = CarbonImmutable::parse($end);

        while ($day->toDateString() >= $start) {
            $date = $day->toDateString();
            $entries = [];

            foreach ($this->entries($tenantId, $date, fn (Builder $query) => $query->where('cs.teacher_employee_id', $employeeId)) as $entry) {
                if ($this->holiday($entry, $date) === null) {
                    $entries[] = $entry;
                }
            }

            array_push($lessons, ...$this->present($tenantId, $date, $entries, []));
            $day = $day->subDay();
        }

        return $lessons;
    }

    /**
     * Satu pertemuan (baris jadwal × tanggal) bila memang terjadwal hari itu; selain itu null.
     */
    public function find(string $tenantId, string $entryId, string $date): ?stdClass
    {
        foreach ($this->entries($tenantId, $date, fn (Builder $query) => $query->where('e.id', $entryId)) as $entry) {
            return $this->holiday($entry, $date) === null ? $entry : null;
        }

        return null;
    }

    /**
     * @param  callable(Builder): mixed  $filter
     * @return list<stdClass>
     */
    private function entries(string $tenantId, string $date, callable $filter): array
    {
        $query = DB::table('ac_schedule_entries as e')
            ->join('ac_classes as c', 'c.id', '=', 'e.class_id')
            ->join('ac_academic_years as y', 'y.id', '=', 'c.academic_year_id')
            ->join('ac_class_subjects as cs', 'cs.id', '=', 'e.class_subject_id')
            ->join('ac_subjects as s', 's.id', '=', 'cs.subject_id')
            ->join('organizations as o', 'o.id', '=', 'c.organization_id')
            ->where('e.tenant_id', $tenantId)
            ->where('c.is_active', true)
            ->where('e.day_of_week', CarbonImmutable::parse($date)->dayOfWeekIso)
            ->where('y.start_date', '<=', $date)
            ->where('y.end_date', '>=', $date);

        $filter($query);

        return array_values($query
            ->orderBy('e.start_time')
            ->orderBy('c.grade')
            ->orderByRaw('lower(c.name)')
            ->get([
                'e.id', 'e.class_id', 'e.class_subject_id', 'e.start_time', 'e.end_time', 'c.name as class_name', 'c.organization_id',
                'c.academic_year_id', 'y.end_date as year_end', 'o.name as organization', 's.name as subject', 'cs.teacher_employee_id',
            ])
            ->all());
    }

    private function holiday(stdClass $entry, string $date): ?string
    {
        $id = (string) $entry->academic_year_id;
        $year = $this->years[$id] ??= AcademicYear::query()->withoutGlobalScope('tenant')->whereKey($id)->first();

        return $year instanceof AcademicYear ? $this->schoolDays->holidayOn($year, $date) : null;
    }

    /**
     * @param  list<stdClass>  $entries  pertemuan terjadwal
     * @param  list<stdClass>  $orphans  catatan yang jadwalnya sudah dihapus (lengkap dengan nama kelas/lembaga/mapel)
     * @return list<Lesson>
     */
    private function present(string $tenantId, string $date, array $entries, array $orphans): array
    {
        $records = $entries === [] ? collect() : $this->recordQuery()
            ->where('r.date', $date)
            ->whereIn('r.schedule_entry_id', array_map(fn (stdClass $entry): string => (string) $entry->id, $entries))
            ->get(['r.*', 'p.name as recorded_by'])
            ->keyBy('schedule_entry_id');

        $employeeIds = [];

        foreach ([...$entries, ...$records->all(), ...$orphans] as $row) {
            foreach (['teacher_employee_id', 'substitute_employee_id'] as $column) {
                if (isset($row->{$column})) {
                    $employeeIds[] = (string) $row->{$column};
                }
            }
        }

        $names = $this->employees->describe($tenantId, array_values(array_unique($employeeIds)));
        $person = fn (?string $id): ?array => $id === null ? null : ['id' => $id, 'name' => $names[$id]['name'] ?? '—'];
        $leaves = $this->leaves->byDate($tenantId, array_values(array_unique($employeeIds)), $date, $date);
        $leave = fn (?string $id): ?string => $id === null ? null : ($leaves[$id][$date] ?? null);
        $lessons = [];

        foreach ($entries as $entry) {
            $record = $records->get((string) $entry->id);
            // Pengampu yang tercatat saat itu; bila belum dicatat, pengampu sekarang.
            $teacher = $record !== null ? $record->teacher_employee_id : $entry->teacher_employee_id;

            $lessons[] = [
                'key' => $entry->id.'|'.$date,
                'entry_id' => (string) $entry->id,
                'date' => $date,
                'start' => substr((string) $entry->start_time, 0, 5),
                'end' => substr((string) $entry->end_time, 0, 5),
                'class_id' => (string) $entry->class_id,
                'class_name' => (string) $entry->class_name,
                'organization_id' => (string) $entry->organization_id,
                'organization' => (string) $entry->organization,
                'subject' => (string) $entry->subject,
                'teacher' => $person($teacher === null ? null : (string) $teacher),
                'leave' => $leave($teacher === null ? null : (string) $teacher),
                'record' => $record === null ? null : $this->recordView($record, $person),
            ];
        }

        foreach ($orphans as $record) {
            $lessons[] = [
                'key' => 'r|'.$record->id,
                'entry_id' => null,
                'date' => $date,
                'start' => substr((string) $record->start_time, 0, 5),
                'end' => substr((string) $record->end_time, 0, 5),
                'class_id' => (string) $record->class_id,
                'class_name' => (string) $record->class_name,
                'organization_id' => (string) $record->organization_id,
                'organization' => (string) $record->organization,
                'subject' => (string) $record->subject,
                'teacher' => $person($record->teacher_employee_id === null ? null : (string) $record->teacher_employee_id),
                'leave' => $leave($record->teacher_employee_id === null ? null : (string) $record->teacher_employee_id),
                'record' => $this->recordView($record, $person),
            ];
        }

        usort($lessons, fn (array $a, array $b): int => [$a['start'], $a['class_name']] <=> [$b['start'], $b['class_name']]);

        return $lessons;
    }

    private function recordQuery(): Builder
    {
        return DB::table('ac_teaching_records as r')
            ->leftJoin('memberships as m', 'm.id', '=', 'r.recorded_by_membership_id')
            ->leftJoin('persons as p', 'p.id', '=', 'm.person_id');
    }

    /**
     * @param  callable(?string): (array{id: string, name: string}|null)  $person
     * @return RecordView
     */
    private function recordView(stdClass $record, callable $person): array
    {
        $status = TeachingStatus::from((string) $record->status);
        $reason = $record->absence_reason === null ? null : AbsenceReason::from((string) $record->absence_reason);

        return [
            'status' => $status->value,
            'status_label' => $status->label(),
            'substitute' => $person($record->substitute_employee_id === null ? null : (string) $record->substitute_employee_id),
            'reason' => $reason?->value,
            'reason_label' => $reason?->label(),
            'note' => $record->note === null ? null : (string) $record->note,
            'source' => (string) $record->source,
            'recorded_by' => $record->recorded_by === null ? null : (string) $record->recorded_by,
        ];
    }
}
