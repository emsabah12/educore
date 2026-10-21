<?php

namespace Modules\Academic\Application\Classroom;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Application\Student\ManageEnrollments;
use Modules\Academic\Application\Student\StudentInput;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\ClassMember;
use Modules\Academic\Domain\Classroom\Exceptions\ClassException;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Academic\Domain\Student\Enrollment;
use Modules\Academic\Domain\Student\EnrollmentEndReason;
use Modules\Academic\Domain\Student\Exceptions\StudentException;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Support\BusinessClock;

/**
 * Proses akhir tahun satu kelas (PRD-002 §15, AC-2): tiap siswa yang masih di kelas tahun ajaran X
 * dipilihkan Naik / Tinggal kelas (ke kelas tahun ajaran berikutnya) / Lulus / Tidak diproses.
 *
 * Aturan [OWNER, 2026-10-20]:
 * - Tahun ajaran tujuan = tahun ajaran berikutnya di kalender yang sama (mulai paling awal sesudah X).
 * - Naik/tinggal: masuk kelas aktif tahun tujuan di lembaga yang sama (tingkat sama = tinggal,
 *   lebih tinggi = naik), mulai tanggal mulai tahun tujuan. Keanggotaan kelas X TIDAK ditutup
 *   (tetap sebagai riwayat).
 * - Lulus: pendaftaran ditutup dengan alasan LULUS pada tanggal yang dipilih (tidak di masa depan);
 *   kelas yang masih terbuka ikut ditutup (ManageEnrollments::close).
 * - Semua atau tidak sama sekali; masalah tiap siswa disebut sekaligus.
 * - Tahun X boleh sudah lewat: kelas X hanya dibaca.
 *
 * Hak akses (academic.classes.manage di lembaga kelas; + academic.students.manage bila ada yang
 * lulus) dicek pemanggil.
 *
 * @phpstan-type TargetOption array{value: string, label: string, grade: int}
 * @phpstan-type PlanRow array{member_id: string, student_id: string, name: string, gender: string|null, nisn: string|null, student_number: string|null, default: string, next_class: string|null}
 */
final class YearEndProcess
{
    /** Pilihan "Lulus". */
    public const GRADUATE = 'lulus';

    /** Pilihan "Tidak diproses". */
    public const SKIP = 'lewati';

    /** Batas jumlah siswa sekali proses (satu rombel). */
    public const MAX_STUDENTS = 200;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ManageClassMembers $members,
        private readonly ManageEnrollments $enrollments,
    ) {}

    /**
     * Tahun ajaran berikutnya di kalender yang sama dengan tahun ajaran kelas ini.
     */
    public function nextYear(AcademicYear $year): ?AcademicYear
    {
        return AcademicYear::query()
            ->withoutGlobalScope('tenant')
            ->where('calendar_id', $year->calendar_id)
            ->where('start_date', '>', $year->start_date->toDateString())
            ->orderBy('start_date')
            ->first();
    }

    /**
     * Isi halaman proses akhir tahun: kelas tujuan, siswa, dan pilihan bawaan tiap siswa.
     *
     * Bawaan: Naik ke kelas pertama bertingkat +1; bila tidak ada kelas bertingkat lebih tinggi
     * (dan kelas tahun tujuan sudah disiapkan) → Lulus (hanya bila boleh mencatat lulus); selain itu
     * Tidak diproses. Siswa yang sudah punya kelas di tahun tujuan selalu Tidak diproses.
     *
     * @return array{
     *     year: array{id: string, name: string, end_date: string},
     *     nextYear: array{id: string, name: string, start_date: string, past: bool}|null,
     *     targets: list<TargetOption>,
     *     hasNextClasses: bool,
     *     rows: list<PlanRow>,
     *     graduationDefault: string,
     * }
     */
    public function plan(SchoolClass $class, bool $canGraduate): array
    {
        $year = AcademicYear::query()->withoutGlobalScope('tenant')->whereKey($class->academic_year_id)->firstOrFail();
        $next = $this->nextYear($year);
        $today = BusinessClock::todayString();
        $graduationDefault = min($year->end_date->toDateString(), $today);

        $allNext = $next === null ? [] : $this->activeClasses($class->organization_id, $next->id);
        $targets = array_values(array_filter($allNext, fn (array $target): bool => $target['grade'] >= $class->grade));
        $promote = null;
        $hasHigher = false;

        foreach ($targets as $target) {
            $hasHigher = $hasHigher || $target['grade'] > $class->grade;

            if ($promote === null && $target['grade'] === $class->grade + 1) {
                $promote = $target['value'];
            }
        }

        $default = match (true) {
            $promote !== null => $promote,
            $allNext !== [] && ! $hasHigher && $canGraduate => self::GRADUATE,
            default => self::SKIP,
        };

        $nextClassOf = $next === null ? [] : $this->nextClassNames($class, $next->id);
        $rows = [];

        foreach ($this->openMemberRows($class->id) as $row) {
            $nextClass = $nextClassOf[$row['enrollment_id']] ?? null;
            $rows[] = [
                'member_id' => $row['member_id'],
                'student_id' => $row['student_id'],
                'name' => $row['name'],
                'gender' => $row['gender'],
                'nisn' => $row['nisn'],
                'student_number' => $row['student_number'],
                'default' => $nextClass === null ? $default : self::SKIP,
                'next_class' => $nextClass,
            ];
        }

        return [
            'year' => ['id' => $year->id, 'name' => $year->name, 'end_date' => $year->end_date->toDateString()],
            'nextYear' => $next === null ? null : [
                'id' => $next->id,
                'name' => $next->name,
                'start_date' => $next->start_date->toDateString(),
                'past' => ManageClasses::isPastYear($next),
            ],
            'targets' => $targets,
            'hasNextClasses' => $allNext !== [],
            'rows' => $rows,
            'graduationDefault' => $graduationDefault,
        ];
    }

    /**
     * @param  array<string, string>  $decisions  id keanggotaan kelas => id kelas tujuan | lulus | lewati
     * @return array{promoted: int, retained: int, graduated: int}
     *
     * @throws ClassException
     */
    public function process(string $tenantId, string $classId, array $decisions, ?string $graduationDate): array
    {
        $chosen = array_filter($decisions, fn (string $value): bool => $value !== self::SKIP);

        if ($chosen === []) {
            throw ClassException::make('decisions', 'Pilih minimal satu siswa untuk diproses (naik, tinggal kelas, atau lulus).');
        }

        if (count($decisions) > self::MAX_STUDENTS) {
            throw ClassException::make('decisions', 'Paling banyak '.self::MAX_STUDENTS.' siswa sekali proses.');
        }

        $graduating = in_array(self::GRADUATE, $chosen, true);

        if ($graduating) {
            $this->assertGraduationDate($graduationDate);
        }

        return $this->guard(function () use ($tenantId, $classId, $chosen, $graduating, $graduationDate): array {
            $class = Str::isUuid($classId)
                ? SchoolClass::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($classId)->lockForUpdate()->first()
                : null;

            if (! $class instanceof SchoolClass) {
                throw ClassException::make('form', 'Kelas tidak ditemukan di yayasan ini.');
            }

            $year = AcademicYear::query()->withoutGlobalScope('tenant')->whereKey($class->academic_year_id)->firstOrFail();
            $next = $this->nextYear($year);

            if ($next === null) {
                throw ClassException::make('form', "Tahun ajaran sesudah {$year->name} belum dibuat di kalender akademik.");
            }

            $members = ClassMember::query()
                ->withoutGlobalScope('tenant')
                ->where('class_id', $class->id)
                ->whereNull('end_date')
                ->whereIn('id', array_values(array_filter(array_keys($chosen), fn (string $id): bool => Str::isUuid($id))))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($members->count() !== count($chosen)) {
                throw ClassException::make('form', 'Data kelas berubah sejak halaman dibuka. Muat ulang halaman lalu periksa lagi pilihannya.');
            }

            $targets = [];

            foreach ($this->activeClasses($class->organization_id, $next->id) as $target) {
                $targets[$target['value']] = $target;
            }

            $names = $this->namesByMember($class->id);
            $nextClassOf = $this->nextClassNames($class, $next->id);
            $plans = [];
            $problems = [];

            foreach ($chosen as $memberId => $value) {
                $member = $members->get($memberId);

                if (! $member instanceof ClassMember) {
                    continue;
                }

                $name = $names[$member->id] ?? 'Siswa';
                $enrollment = Enrollment::query()->withoutGlobalScope('tenant')->whereKey($member->enrollment_id)->lockForUpdate()->first();

                if (! $enrollment instanceof Enrollment || ! $enrollment->isOpen() || $enrollment->organization_id !== $class->organization_id) {
                    $problems[] = "{$name} (bukan siswa aktif lembaga ini)";
                } elseif (isset($nextClassOf[$enrollment->id])) {
                    $problems[] = "{$name} (sudah punya kelas {$nextClassOf[$enrollment->id]} di tahun ajaran {$next->name})";
                } elseif ($value === self::GRADUATE) {
                    $plans[] = ['name' => $name, 'enrollment' => $enrollment, 'target' => null];
                } elseif (! isset($targets[$value]) || $targets[$value]['grade'] < $class->grade) {
                    $problems[] = "{$name} (kelas tujuan tidak tersedia di tahun ajaran {$next->name})";
                } else {
                    $plans[] = ['name' => $name, 'enrollment' => $enrollment, 'target' => $targets[$value]];
                }
            }

            // Semua atau tidak sama sekali: masalah disebut sekaligus agar operator tahu siapa saja.
            if ($problems !== []) {
                throw ClassException::make('decisions', count($problems).' siswa tidak bisa diproses: '.implode(', ', $problems).'. Tidak ada yang disimpan.');
            }

            $counts = ['promoted' => 0, 'retained' => 0, 'graduated' => 0];

            foreach ($plans as $plan) {
                if ($plan['target'] === null) {
                    $this->graduate($tenantId, $plan['enrollment'], (string) $graduationDate, $plan['name']);
                    $counts['graduated']++;

                    continue;
                }

                try {
                    $this->members->placeNewEnrollment($plan['enrollment'], $plan['target']['value']);
                } catch (ClassException $exception) {
                    throw ClassException::make('decisions', "{$plan['name']}: {$exception->getMessage()} Tidak ada yang disimpan.");
                }

                if ($plan['target']['grade'] > $class->grade) {
                    $counts['promoted']++;
                } else {
                    $counts['retained']++;
                }
            }

            $changes = [
                'kelas' => $class->name,
                'tahun_ajaran' => $year->name,
                'tahun_ajaran_tujuan' => $next->name,
                'naik' => $counts['promoted'],
                'tinggal' => $counts['retained'],
                'lulus' => $counts['graduated'],
            ];

            if ($graduating) {
                $changes['tanggal_lulus'] = $graduationDate;
            }

            $this->audit->record(
                AcademicAuditAction::CLASS_YEAR_END_PROCESSED,
                AcademicAuditAction::SUBJECT_CLASS,
                $class->id,
                $changes,
                organizationId: $class->organization_id,
                tenantId: $tenantId,
            );

            return $counts;
        });
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function graduate(string $tenantId, Enrollment $enrollment, string $date, string $name): void
    {
        try {
            $this->enrollments->close($tenantId, $enrollment->id, $date, EnrollmentEndReason::Graduated, null);
        } catch (StudentException $exception) {
            throw ClassException::make('graduation_date', "{$name}: {$exception->getMessage()} Tidak ada yang disimpan.");
        }
    }

    private function assertGraduationDate(?string $date): void
    {
        if ($date === null || ! StudentInput::isDate($date)) {
            throw ClassException::make('graduation_date', 'Tanggal lulus wajib diisi dengan tanggal yang benar.');
        }

        if ($date > BusinessClock::todayString()) {
            throw ClassException::make('graduation_date', 'Tanggal lulus tidak boleh di masa depan.');
        }
    }

    /**
     * Kelas aktif di lembaga & tahun ajaran tertentu, urut tingkat lalu nama.
     *
     * @return list<TargetOption>
     */
    private function activeClasses(string $organizationId, string $academicYearId): array
    {
        $classes = SchoolClass::query()
            ->withoutGlobalScope('tenant')
            ->where('organization_id', $organizationId)
            ->where('academic_year_id', $academicYearId)
            ->where('is_active', true)
            ->orderBy('grade')
            ->orderBy('name')
            ->get();

        $options = [];

        foreach ($classes as $target) {
            $options[] = ['value' => $target->id, 'label' => $target->name, 'grade' => $target->grade];
        }

        return $options;
    }

    /**
     * Siswa kelas ini yang sudah punya kelas terbuka di tahun tujuan: id pendaftaran => nama kelas.
     *
     * @return array<string, string>
     */
    private function nextClassNames(SchoolClass $class, string $nextYearId): array
    {
        $rows = DB::table('ac_class_members as cm')
            ->join('ac_class_members as nx', 'nx.enrollment_id', '=', 'cm.enrollment_id')
            ->join('ac_classes as c', 'c.id', '=', 'nx.class_id')
            ->where('cm.class_id', $class->id)
            ->whereNull('cm.end_date')
            ->where('nx.academic_year_id', $nextYearId)
            ->whereNull('nx.end_date')
            ->get(['cm.enrollment_id', 'c.name']);

        $names = [];

        foreach ($rows as $row) {
            $names[(string) $row->enrollment_id] = (string) $row->name;
        }

        return $names;
    }

    /**
     * @return list<array{member_id: string, enrollment_id: string, student_id: string, name: string, gender: string|null, nisn: string|null, student_number: string|null}>
     */
    private function openMemberRows(string $classId): array
    {
        $rows = DB::table('ac_class_members as cm')
            ->join('ac_enrollments as e', 'e.id', '=', 'cm.enrollment_id')
            ->join('ac_students as s', 's.id', '=', 'e.student_id')
            ->join('memberships as m', 'm.id', '=', 's.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('cm.class_id', $classId)
            ->whereNull('cm.end_date')
            ->orderBy('p.name')
            ->orderBy('cm.id')
            ->get(['cm.id', 'cm.enrollment_id', 'e.student_id', 'e.student_number', 's.nisn', 'p.name', 'p.gender']);

        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                'member_id' => (string) $row->id,
                'enrollment_id' => (string) $row->enrollment_id,
                'student_id' => (string) $row->student_id,
                'name' => (string) $row->name,
                'gender' => $row->gender === null ? null : (string) $row->gender,
                'nisn' => $row->nisn === null ? null : (string) $row->nisn,
                'student_number' => $row->student_number === null ? null : (string) $row->student_number,
            ];
        }

        return $result;
    }

    /**
     * @return array<string, string> id keanggotaan => nama siswa
     */
    private function namesByMember(string $classId): array
    {
        $names = [];

        foreach ($this->openMemberRows($classId) as $row) {
            $names[$row['member_id']] = $row['name'];
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
            // Siswa yang sama dimasukkan ke kelas tahun depan pada saat bersamaan.
            throw ClassException::make('decisions', 'Data kelas berubah saat disimpan (mungkin diproses bersamaan). Muat ulang halaman lalu coba lagi.');
        }
    }
}
