<?php

namespace Modules\Academic\Application\Classroom;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Application\Student\StudentInput;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\ClassMember;
use Modules\Academic\Domain\Classroom\Exceptions\ClassException;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Academic\Domain\Student\Enrollment;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Support\BusinessClock;

/**
 * Anggota kelas (PRD-002 §4.2, §6, §9; AC-1d-b): masukkan siswa (banyak sekaligus, satu transaksi),
 * pindah kelas, keluarkan dari kelas.
 *
 * Aturan [OWNER, 2026-10-20]:
 * - Siswa yang bisa dimasukkan: pendaftaran TERBUKA di lembaga kelas yang belum punya kelas terbuka
 *   di tahun ajaran itu. Kelas wajib aktif dan tahun ajarannya belum lewat.
 * - Tanggal masuk kelas: tahun berjalan → dipilih (bawaan hari ini), tidak sebelum awal tahun ajaran
 *   maupun tanggal masuk siswa ke lembaga, tidak di masa depan. Tahun ajaran yang belum mulai →
 *   otomatis tanggal mulai tahun ajaran itu.
 * - Pindah kelas: ke kelas aktif di lembaga & tahun ajaran yang sama; lama ditutup, baru dibuka.
 * - Keluarkan: hanya keanggotaan kelas yang ditutup; pendaftaran di lembaga tidak berubah.
 *
 * Hak akses (academic.classes.manage di lembaga kelas) dicek pemanggil lewat Gate.
 */
final class ManageClassMembers
{
    public const MAX_PER_SAVE = 100;

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Siswa yang bisa dimasukkan ke kelas ini.
     *
     * @return list<array{enrollment_id: string, student_id: string, name: string, gender: string|null, nisn: string|null, student_number: string|null}>
     */
    public function candidates(SchoolClass $class): array
    {
        $rows = DB::table('ac_enrollments as e')
            ->join('ac_students as s', 's.id', '=', 'e.student_id')
            ->join('memberships as m', 'm.id', '=', 's.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('e.tenant_id', $class->tenant_id)
            ->where('e.organization_id', $class->organization_id)
            ->whereNull('e.end_date')
            ->whereNotExists(function (Builder $members) use ($class): void {
                $members->select(DB::raw(1))
                    ->from('ac_class_members as cm')
                    ->whereColumn('cm.enrollment_id', 'e.id')
                    ->where('cm.academic_year_id', $class->academic_year_id)
                    ->whereNull('cm.end_date');
            })
            ->orderBy('p.name')
            ->orderBy('e.id')
            ->get(['e.id', 'e.student_id', 'e.student_number', 's.nisn', 'p.name', 'p.gender']);

        $candidates = [];

        foreach ($rows as $row) {
            $candidates[] = [
                'enrollment_id' => (string) $row->id,
                'student_id' => (string) $row->student_id,
                'name' => (string) $row->name,
                'gender' => $row->gender === null ? null : (string) $row->gender,
                'nisn' => $row->nisn === null ? null : (string) $row->nisn,
                'student_number' => $row->student_number === null ? null : (string) $row->student_number,
            ];
        }

        return $candidates;
    }

    /**
     * Tahun ajaran yang belum mulai: tanggal masuk kelas otomatis tanggal mulai tahun ajaran.
     */
    public static function startsInFuture(AcademicYear $year): bool
    {
        return $year->start_date->toDateString() > BusinessClock::todayString();
    }

    // ── Masukkan ─────────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $enrollmentIds
     * @return int jumlah siswa yang dimasukkan
     *
     * @throws ClassException
     */
    public function add(string $tenantId, string $classId, array $enrollmentIds, ?string $date): int
    {
        $enrollmentIds = array_values(array_unique($enrollmentIds));

        if ($enrollmentIds === []) {
            throw ClassException::make('enrollment_ids', 'Pilih minimal satu siswa.');
        }

        if (count($enrollmentIds) > self::MAX_PER_SAVE) {
            throw ClassException::make('enrollment_ids', 'Paling banyak '.self::MAX_PER_SAVE.' siswa sekali simpan.');
        }

        return $this->guard(function () use ($tenantId, $classId, $enrollmentIds, $date): int {
            [$class, $year] = $this->lockOpenClass($tenantId, $classId);
            $startDate = $this->startDate($year, $date, 'start_date');

            $enrollments = Enrollment::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->whereIn('id', array_values(array_filter($enrollmentIds, fn (string $id): bool => Str::isUuid($id))))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $names = $this->namesOf($enrollmentIds);
            $problems = [];

            foreach ($enrollmentIds as $enrollmentId) {
                $enrollment = $enrollments->get($enrollmentId);
                $name = $names[$enrollmentId] ?? 'Siswa';

                if (! $enrollment instanceof Enrollment || $enrollment->organization_id !== $class->organization_id || ! $enrollment->isOpen()) {
                    $problems[] = "{$name} (bukan siswa aktif lembaga ini)";
                } elseif ($this->hasOpenClass($enrollment->id, $class->academic_year_id)) {
                    $problems[] = "{$name} (sudah punya kelas di tahun ajaran ini)";
                } elseif ($enrollment->start_date->toDateString() > $startDate) {
                    $problems[] = "{$name} (baru masuk lembaga {$enrollment->start_date->toDateString()})";
                }
            }

            // Semua atau tidak sama sekali: masalah disebut sekaligus agar operator tahu siapa saja.
            if ($problems !== []) {
                throw ClassException::make('enrollment_ids', count($problems).' siswa tidak bisa dimasukkan: '.implode(', ', $problems).'.');
            }

            foreach ($enrollmentIds as $enrollmentId) {
                $this->open($class, $enrollmentId, $startDate);
            }

            $this->audit->record(
                AcademicAuditAction::CLASS_MEMBERS_ADDED,
                AcademicAuditAction::SUBJECT_CLASS,
                $class->id,
                ['kelas' => $class->name, 'jumlah' => count($enrollmentIds), 'tanggal' => $startDate],
                organizationId: $class->organization_id,
                tenantId: $tenantId,
            );

            return count($enrollmentIds);
        });
    }

    /**
     * Siswa baru langsung masuk kelas dari form Tambah siswa [ASUMSI, disetujui owner 2026-10-20]:
     * tanggal masuk kelas = tanggal masuk lembaga, atau awal tahun ajaran bila lebih awal.
     * Pemanggil wajib di dalam transaksi.
     *
     * @throws ClassException
     */
    public function placeNewEnrollment(Enrollment $enrollment, string $classId): SchoolClass
    {
        [$class, $year] = $this->lockOpenClass($enrollment->tenant_id, $classId, 'class_id');

        if ($class->organization_id !== $enrollment->organization_id) {
            throw ClassException::make('class_id', 'Kelas harus di lembaga yang sama dengan pendaftaran.');
        }

        $startDate = max($enrollment->start_date->toDateString(), $year->start_date->toDateString());

        if ($startDate > $year->end_date->toDateString()) {
            throw ClassException::make('class_id', 'Tanggal masuk sesudah tahun ajaran kelas ini selesai.');
        }

        $this->open($class, $enrollment->id, $startDate);

        return $class;
    }

    // ── Pindah & keluarkan ───────────────────────────────────────────────────

    /**
     * @throws ClassException
     */
    public function move(string $tenantId, string $memberId, string $targetClassId, ?string $date): SchoolClass
    {
        return $this->guard(function () use ($tenantId, $memberId, $targetClassId, $date): SchoolClass {
            [$member, $year] = $this->lockOpenMember($tenantId, $memberId);

            if ($targetClassId === $member->class_id) {
                throw ClassException::make('target_class_id', 'Pilih kelas yang berbeda.');
            }

            [$target] = $this->lockOpenClass($tenantId, $targetClassId, 'target_class_id');

            if ($target->organization_id !== $member->organization_id || $target->academic_year_id !== $member->academic_year_id) {
                throw ClassException::make('target_class_id', 'Kelas tujuan harus di lembaga dan tahun ajaran yang sama.');
            }

            $moveDate = $this->changeDate($year, $member, $date);
            $from = SchoolClass::query()->withoutGlobalScope('tenant')->whereKey($member->class_id)->firstOrFail();

            $member->end_date = CarbonImmutable::parse($moveDate);
            $member->save();
            $this->open($target, $member->enrollment_id, $moveDate);

            $this->audit->record(
                AcademicAuditAction::CLASS_MEMBER_MOVED,
                AcademicAuditAction::SUBJECT_CLASS,
                $target->id,
                ['dari' => $from->name, 'ke' => $target->name, 'tanggal' => $moveDate],
                organizationId: $target->organization_id,
                tenantId: $tenantId,
            );

            return $target;
        });
    }

    /**
     * @throws ClassException
     */
    public function remove(string $tenantId, string $memberId, ?string $date): SchoolClass
    {
        return $this->guard(function () use ($tenantId, $memberId, $date): SchoolClass {
            [$member, $year] = $this->lockOpenMember($tenantId, $memberId);
            $endDate = $this->changeDate($year, $member, $date);
            $class = SchoolClass::query()->withoutGlobalScope('tenant')->whereKey($member->class_id)->firstOrFail();

            $member->end_date = CarbonImmutable::parse($endDate);
            $member->save();

            $this->audit->record(
                AcademicAuditAction::CLASS_MEMBER_REMOVED,
                AcademicAuditAction::SUBJECT_CLASS,
                $class->id,
                ['kelas' => $class->name, 'tanggal' => $endDate],
                organizationId: $class->organization_id,
                tenantId: $tenantId,
            );

            return $class;
        });
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function open(SchoolClass $class, string $enrollmentId, string $startDate): void
    {
        $member = new ClassMember;
        $member->fill([
            'tenant_id' => $class->tenant_id,
            'organization_id' => $class->organization_id,
            'academic_year_id' => $class->academic_year_id,
            'class_id' => $class->id,
            'enrollment_id' => $enrollmentId,
            'start_date' => $startDate,
        ]);
        $member->save();
    }

    /**
     * Kelas tujuan wajib ada, aktif, dan tahun ajarannya belum lewat.
     *
     * @return array{0: SchoolClass, 1: AcademicYear}
     */
    private function lockOpenClass(string $tenantId, string $classId, string $field = 'form'): array
    {
        $class = Str::isUuid($classId)
            ? SchoolClass::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($classId)->lockForUpdate()->first()
            : null;

        if (! $class instanceof SchoolClass) {
            throw ClassException::make($field, 'Kelas tidak ditemukan di yayasan ini.');
        }

        $year = AcademicYear::query()->withoutGlobalScope('tenant')->whereKey($class->academic_year_id)->firstOrFail();

        if (ManageClasses::isPastYear($year)) {
            throw ClassException::pastYear();
        }

        if (! $class->is_active) {
            throw ClassException::make($field, "Kelas {$class->name} nonaktif, jadi tidak bisa diisi siswa.");
        }

        return [$class, $year];
    }

    /**
     * @return array{0: ClassMember, 1: AcademicYear}
     */
    private function lockOpenMember(string $tenantId, string $memberId): array
    {
        $member = Str::isUuid($memberId)
            ? ClassMember::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($memberId)->lockForUpdate()->first()
            : null;

        if (! $member instanceof ClassMember || ! $member->isOpen()) {
            throw ClassException::make('form', 'Siswa ini sudah tidak tercatat di kelas tersebut. Muat ulang halaman.');
        }

        $year = AcademicYear::query()->withoutGlobalScope('tenant')->whereKey($member->academic_year_id)->firstOrFail();

        if (ManageClasses::isPastYear($year)) {
            throw ClassException::pastYear();
        }

        return [$member, $year];
    }

    /**
     * Tanggal masuk kelas: tahun yang belum mulai → tanggal mulainya; selain itu tanggal yang dipilih.
     */
    private function startDate(AcademicYear $year, ?string $date, string $field): string
    {
        if (self::startsInFuture($year)) {
            return $year->start_date->toDateString();
        }

        $value = $this->pastOrToday($date, $field, 'Tanggal masuk kelas');

        if ($value < $year->start_date->toDateString()) {
            throw ClassException::make($field, "Tanggal masuk kelas tidak boleh sebelum awal tahun ajaran ({$year->start_date->toDateString()}).");
        }

        if ($value > $year->end_date->toDateString()) {
            throw ClassException::make($field, 'Tanggal masuk kelas sesudah tahun ajaran selesai.');
        }

        return $value;
    }

    /**
     * Tanggal pindah/keluar: tahun yang belum mulai → tanggal masuk kelasnya; selain itu tanggal yang
     * dipilih, tidak sebelum siswa masuk kelas ini.
     */
    private function changeDate(AcademicYear $year, ClassMember $member, ?string $date): string
    {
        if (self::startsInFuture($year)) {
            return $member->start_date->toDateString();
        }

        $value = $this->pastOrToday($date, 'date', 'Tanggal');

        if ($value < $member->start_date->toDateString()) {
            throw ClassException::make('date', "Tanggal tidak boleh sebelum siswa masuk kelas ini ({$member->start_date->toDateString()}).");
        }

        return $value;
    }

    private function pastOrToday(?string $date, string $field, string $label): string
    {
        if ($date === null || ! StudentInput::isDate($date)) {
            throw ClassException::make($field, "{$label} wajib diisi dengan tanggal yang benar.");
        }

        if ($date > BusinessClock::todayString()) {
            throw ClassException::make($field, "{$label} tidak boleh di masa depan.");
        }

        return $date;
    }

    private function hasOpenClass(string $enrollmentId, string $academicYearId): bool
    {
        return DB::table('ac_class_members')
            ->where('enrollment_id', $enrollmentId)
            ->where('academic_year_id', $academicYearId)
            ->whereNull('end_date')
            ->exists();
    }

    /**
     * @param  list<string>  $enrollmentIds
     * @return array<string, string>
     */
    private function namesOf(array $enrollmentIds): array
    {
        $names = [];
        $valid = array_values(array_filter($enrollmentIds, fn (string $id): bool => Str::isUuid($id)));

        if ($valid === []) {
            return [];
        }

        $rows = DB::table('ac_enrollments as e')
            ->join('ac_students as s', 's.id', '=', 'e.student_id')
            ->join('memberships as m', 'm.id', '=', 's.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->whereIn('e.id', $valid)
            ->get(['e.id', 'p.name']);

        foreach ($rows as $row) {
            $names[(string) $row->id] = (string) $row->name;
        }

        return $names;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function guard(Closure $callback): mixed
    {
        try {
            return DB::transaction($callback);
        } catch (UniqueConstraintViolationException) {
            // Siswa yang sama dimasukkan ke kelas lain pada saat bersamaan.
            throw ClassException::make('enrollment_ids', 'Data kelas berubah saat disimpan (mungkin diisi bersamaan). Muat ulang halaman lalu coba lagi.');
        }
    }
}
