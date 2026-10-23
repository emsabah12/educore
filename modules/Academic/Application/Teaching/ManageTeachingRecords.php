<?php

namespace Modules\Academic\Application\Teaching;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Domain\Teaching\AbsenceReason;
use Modules\Academic\Domain\Teaching\Exceptions\TeachingException;
use Modules\Academic\Domain\Teaching\TeachingStatus;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Contracts\ActiveEmployees;
use stdClass;

/**
 * Mencatat presensi mengajar per pertemuan (PRD-002 §20.2, OD-AC-14).
 *
 * - Guru: "Sudah mengajar" untuk pertemuannya sendiri, paling cepat saat jam pelajaran mulai, paling
 *   lama 7 hari ke belakang; tidak bisa mengubah catatan petugas [ASUMSI].
 * - Petugas (guru piket / Operator Akademik / Kepala Lembaga): Mengajar / Digantikan / Kosong.
 *   Siapa boleh pada tanggal mana dicek pemanggil (TeachingAccess).
 * - Pertemuan harus benar-benar terjadwal pada tanggal itu, tidak di masa depan.
 * - Keterangan tidak masuk audit.
 */
final class ManageTeachingRecords
{
    public const TEACHER_WINDOW_DAYS = 7;

    public const NOTE_MAX_LENGTH = 300;

    /** Guru pengganti boleh ditunjuk sebelum hari-H, paling jauh 7 hari ke depan (AC-6b). */
    public const ASSIGN_AHEAD_DAYS = 7;

    public function __construct(
        private readonly Lessons $lessons,
        private readonly ActiveEmployees $employees,
        private readonly SubstituteFinder $finder,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws TeachingException
     */
    public function markTaught(string $tenantId, string $entryId, string $date, string $employeeId, string $membershipId): void
    {
        $today = BusinessClock::todayString();

        if ($date > $today || $date < BusinessClock::today()->subDays(self::TEACHER_WINDOW_DAYS)->toDateString()) {
            throw TeachingException::make('form', 'Guru hanya bisa menandai pertemuan '.self::TEACHER_WINDOW_DAYS.' hari terakhir. Hubungi operator lembaga.');
        }

        DB::transaction(function () use ($tenantId, $entryId, $date, $employeeId, $membershipId, $today): void {
            $lesson = $this->lesson($tenantId, $entryId, $date);

            if ((string) $lesson->teacher_employee_id !== $employeeId) {
                throw TeachingException::make('form', 'Pertemuan ini bukan jadwal Anda.');
            }

            if ($date === $today && CarbonImmutable::now(BusinessClock::timezone())->format('H:i') < substr((string) $lesson->start_time, 0, 5)) {
                throw TeachingException::make('form', 'Pelajaran ini belum dimulai (mulai '.str_replace(':', '.', substr((string) $lesson->start_time, 0, 5)).').');
            }

            $existing = $this->existing((string) $lesson->id, $date);

            if ($existing !== null) {
                $status = TeachingStatus::from((string) $existing->status);

                throw TeachingException::make('form', $status === TeachingStatus::Taught
                    ? 'Pertemuan ini sudah tercatat Mengajar.'
                    : 'Pertemuan ini sudah dicatat petugas: '.$status->label().'. Hubungi guru piket atau operator bila keliru.');
            }

            $this->write($tenantId, $lesson, $date, TeachingStatus::Taught, null, null, null, Lessons::SOURCE_TEACHER, $membershipId, null);
        });
    }

    /**
     * Catatan petugas (membuat baru atau mengganti).
     *
     * @throws TeachingException
     */
    public function record(string $tenantId, string $entryId, string $date, TeachingStatus $status, ?string $substituteId, ?AbsenceReason $reason, ?string $note, string $membershipId): void
    {
        $note = $note === null ? null : trim($note);
        $note = $note === '' ? null : $note;

        if ($note !== null && mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            throw TeachingException::make('note', 'Keterangan maksimal '.self::NOTE_MAX_LENGTH.' karakter.');
        }

        // Tanggal yang akan datang: hanya menunjuk guru pengganti, paling jauh 7 hari (AC-6b).
        if ($date > BusinessClock::todayString()
            && ($status !== TeachingStatus::Substituted || $date > BusinessClock::today()->addDays(self::ASSIGN_AHEAD_DAYS)->toDateString())) {
            throw TeachingException::make('date', 'Untuk tanggal yang akan datang hanya bisa menunjuk guru pengganti, paling jauh '.self::ASSIGN_AHEAD_DAYS.' hari ke depan.');
        }

        if ($status === TeachingStatus::Taught) {
            $substituteId = null;
            $reason = null;
        } elseif ($reason === null) {
            throw TeachingException::make('absence_reason', 'Pilih alasan guru tidak mengajar.');
        }

        if ($status !== TeachingStatus::Substituted) {
            $substituteId = null;
        } elseif ($substituteId === null || $substituteId === '') {
            throw TeachingException::make('substitute_employee_id', 'Pilih guru pengganti.');
        }

        DB::transaction(function () use ($tenantId, $entryId, $date, $status, $substituteId, $reason, $note, $membershipId): void {
            $lesson = $this->lesson($tenantId, $entryId, $date);

            // Kelas tahun ajaran yang sudah lewat hanya bisa dilihat (sama dengan AC-1d).
            if (substr((string) $lesson->year_end, 0, 10) < BusinessClock::todayString()) {
                throw TeachingException::make('form', 'Tahun ajaran kelas ini sudah lewat; presensi mengajarnya hanya bisa dilihat.');
            }

            if ($substituteId !== null) {
                if ($substituteId === (string) $lesson->teacher_employee_id) {
                    throw TeachingException::make('substitute_employee_id', 'Guru pengganti tidak boleh guru pengampunya sendiri.');
                }

                if (! $this->employees->isActiveIn($tenantId, $substituteId, (string) $lesson->organization_id)) {
                    throw TeachingException::make('substitute_employee_id', 'Guru pengganti harus pegawai aktif yang ditempatkan di lembaga ini.');
                }

                $busy = $this->finder->unavailableReason($tenantId, $substituteId, $date, substr((string) $lesson->start_time, 0, 5), substr((string) $lesson->end_time, 0, 5), (string) $lesson->id);

                if ($busy !== null) {
                    throw TeachingException::make('substitute_employee_id', 'Guru pengganti ini tidak tersedia pada jam tersebut: '.lcfirst($busy).'.');
                }
            }

            $this->write($tenantId, $lesson, $date, $status, $substituteId, $reason, $note, Lessons::SOURCE_OFFICER, $membershipId, $this->existing((string) $lesson->id, $date));
        });
    }

    /**
     * Batalkan penunjukan pengganti untuk pertemuan hari ini atau yang akan datang.
     *
     * @throws TeachingException
     */
    public function clearSubstitute(string $tenantId, string $entryId, string $date): void
    {
        if ($date < BusinessClock::todayString()) {
            throw TeachingException::make('form', 'Pertemuan yang sudah lewat tidak bisa dibatalkan penggantinya; ubah statusnya di Presensi mengajar.');
        }

        DB::transaction(function () use ($tenantId, $entryId, $date): void {
            $lesson = $this->lesson($tenantId, $entryId, $date);
            $existing = $this->existing((string) $lesson->id, $date);

            if ($existing === null || $existing->status !== TeachingStatus::Substituted->value) {
                throw TeachingException::make('form', 'Pertemuan ini belum punya guru pengganti.');
            }

            DB::table('ac_teaching_records')->where('id', $existing->id)->delete();

            $this->audit->record(
                AcademicAuditAction::TEACHING_RECORDED,
                AcademicAuditAction::SUBJECT_CLASS,
                (string) $lesson->class_id,
                [
                    'tanggal' => $date,
                    'jam' => substr((string) $lesson->start_time, 0, 5).'–'.substr((string) $lesson->end_time, 0, 5),
                    'mapel' => (string) $lesson->subject,
                    'status' => 'Penunjukan pengganti dibatalkan',
                    'pengganti_diisi' => false,
                    'oleh' => 'petugas',
                ],
                organizationId: (string) $lesson->organization_id,
                tenantId: $tenantId,
            );
        });
    }

    /**
     * @throws TeachingException
     */
    private function lesson(string $tenantId, string $entryId, string $date): stdClass
    {
        $lesson = Str::isUuid($entryId) && CarbonImmutable::hasFormat($date, 'Y-m-d') ? $this->lessons->find($tenantId, $entryId, $date) : null;

        if ($lesson === null) {
            throw TeachingException::make('form', 'Tidak ada pertemuan ini pada tanggal tersebut (bukan hari jadwalnya, libur, atau di luar tahun ajaran kelas).');
        }

        return $lesson;
    }

    private function existing(string $entryId, string $date): ?stdClass
    {
        $row = DB::table('ac_teaching_records')->where('schedule_entry_id', $entryId)->where('date', $date)->lockForUpdate()->first();

        return $row instanceof stdClass ? $row : null;
    }

    private function write(string $tenantId, stdClass $lesson, string $date, TeachingStatus $status, ?string $substituteId, ?AbsenceReason $reason, ?string $note, string $source, string $membershipId, ?stdClass $existing): void
    {
        $values = [
            'status' => $status->value,
            'substitute_employee_id' => $substituteId,
            'absence_reason' => $reason?->value,
            'note' => $note,
            'source' => $source,
            'recorded_by_membership_id' => $membershipId,
            'updated_at' => CarbonImmutable::now(),
        ];

        if ($existing === null) {
            DB::table('ac_teaching_records')->insert($values + [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'organization_id' => (string) $lesson->organization_id,
                'academic_year_id' => (string) $lesson->academic_year_id,
                'class_id' => (string) $lesson->class_id,
                'class_subject_id' => (string) $lesson->class_subject_id,
                'schedule_entry_id' => (string) $lesson->id,
                'date' => $date,
                'start_time' => (string) $lesson->start_time,
                'end_time' => (string) $lesson->end_time,
                'teacher_employee_id' => $lesson->teacher_employee_id === null ? null : (string) $lesson->teacher_employee_id,
                'created_at' => CarbonImmutable::now(),
            ]);
        } else {
            DB::table('ac_teaching_records')->where('id', $existing->id)->update($values);
        }

        $this->audit->record(
            AcademicAuditAction::TEACHING_RECORDED,
            AcademicAuditAction::SUBJECT_CLASS,
            (string) $lesson->class_id,
            [
                'tanggal' => $date,
                'jam' => substr((string) $lesson->start_time, 0, 5).'–'.substr((string) $lesson->end_time, 0, 5),
                'mapel' => (string) $lesson->subject,
                'status' => $status->label(),
                'pengganti_diisi' => $substituteId !== null,
                'oleh' => $source === Lessons::SOURCE_TEACHER ? 'guru' : 'petugas',
            ],
            organizationId: (string) $lesson->organization_id,
            tenantId: $tenantId,
        );
    }
}
