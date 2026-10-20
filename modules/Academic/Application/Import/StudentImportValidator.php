<?php

namespace Modules\Academic\Application\Import;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Academic\Application\Calendar\AcademicCalendarResolver;
use Modules\Academic\Application\Student\GuardianData;
use Modules\Academic\Application\Student\NewStudentData;
use Modules\Academic\Application\Student\StudentInput;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Academic\Domain\Student\EntryType;
use Modules\Academic\Domain\Student\GuardianRelation;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\MembershipStatus;
use Modules\Core\Support\BusinessClock;
use Modules\Core\Support\Import\ImportCell;

/**
 * Memeriksa baris file impor siswa tanpa menyimpan apa pun (PRD-002 AC-1e).
 *
 * Aturan isian sama dengan form Tambah siswa (§4.2, §4.5, AC-1b, AC-1d-b). Tambahan khusus impor
 * [OWNER, 2026-10-20]:
 * - satu file = satu lembaga (dipilih saat unggah);
 * - NISN yang sudah tercatat untuk siswa dengan NAMA yang sama → siswa lama, hanya didaftarkan ke
 *   lembaga ini; NISN milik nama lain → Salah;
 * - tanpa kecocokan NISN, nama sama dengan anggota yayasan / baris lain → Peringatan (orang baru
 *   setelah dikonfirmasi);
 * - NISN & nomor induk dobel di dalam file, serta NISN/HP yang tersimpan sebagai angka → Salah;
 * - kelas = nama kelas aktif tahun ajaran berjalan lembaga itu.
 *
 * @phpstan-type CellValue bool|DateTimeImmutable|float|int|string|null
 */
final class StudentImportValidator
{
    private const BIRTH_PLACE_MAX = 100;

    private const ADDRESS_MAX = 500;

    public function __construct(
        private readonly AcademicCalendarResolver $calendars,
    ) {}

    /**
     * @param  list<array{line: int, values: array<string, bool|DateTimeImmutable|float|int|string|null>}>  $rows
     * @param  bool  $canManageClasses  pengguna boleh mengelola kelas di lembaga ini (kolom kelas)
     */
    public function validate(string $tenantId, Organization $organization, bool $canManageClasses, array $rows): StudentImportPreview
    {
        $today = BusinessClock::todayString();
        $classes = $this->classes($tenantId, $organization);

        $drafts = [];

        foreach ($rows as $row) {
            $drafts[] = $this->parse($row['line'], $row['values'], $today, $classes, $canManageClasses);
        }

        $this->checkNisn($tenantId, $organization, $drafts);
        $this->checkStudentNumbers($tenantId, $organization, $drafts);
        $this->checkSimilarPeople($tenantId, $drafts);

        return new StudentImportPreview(array_map(
            fn (StudentImportDraft $draft): StudentImportRow => $this->toRow($tenantId, $organization, $draft),
            $drafts,
        ));
    }

    /**
     * Kelas aktif di tahun ajaran berjalan lembaga ini: nama huruf kecil => kelas. Dipakai juga template.
     *
     * @return array<string, SchoolClass>
     */
    public function classes(string $tenantId, Organization $organization): array
    {
        $year = $this->calendars->currentYearFor($tenantId, $organization->id);

        if ($year === null) {
            return [];
        }

        $classes = [];

        $rows = SchoolClass::query()
            ->withoutGlobalScope('tenant')
            ->where('organization_id', $organization->id)
            ->where('academic_year_id', $year->id)
            ->orderBy('grade')
            ->orderBy('name')
            ->get();

        foreach ($rows as $class) {
            $classes[mb_strtolower($class->name)] = $class;
        }

        return $classes;
    }

    // ── Satu baris ────────────────────────────────────────────────────────────

    /**
     * @param  array<string, bool|DateTimeImmutable|float|int|string|null>  $values
     * @param  array<string, SchoolClass>  $classes
     */
    private function parse(int $line, array $values, string $today, array $classes, bool $canManageClasses): StudentImportDraft
    {
        $draft = new StudentImportDraft($line);
        $value = fn (string $key): bool|DateTimeImmutable|float|int|string|null => $values[$key] ?? null;

        // Nama & jenis kelamin
        $draft->name = StudentInput::name(ImportCell::text($value(StudentImportColumns::NAME)));

        if ($draft->name === null) {
            $draft->error(StudentImportColumns::NAME, 'Wajib diisi.');
        } elseif (mb_strlen($draft->name) > StudentInput::NAME_MAX_LENGTH) {
            $draft->error(StudentImportColumns::NAME, 'Maksimal 200 karakter.');
        }

        $gender = ImportCell::text($value(StudentImportColumns::GENDER));
        $draft->gender = $gender === null ? null : Gender::tryFrom(mb_strtoupper($gender));

        if ($draft->gender === null) {
            $draft->error(StudentImportColumns::GENDER, 'Isi L atau P.');
        }

        // Data pribadi (opsional)
        $draft->birthPlace = $this->limited($draft, StudentImportColumns::BIRTH_PLACE, $value(StudentImportColumns::BIRTH_PLACE), self::BIRTH_PLACE_MAX);
        $draft->address = $this->limited($draft, StudentImportColumns::ADDRESS, $value(StudentImportColumns::ADDRESS), self::ADDRESS_MAX);
        $draft->birthDate = $this->date($draft, StudentImportColumns::BIRTH_DATE, $value(StudentImportColumns::BIRTH_DATE), $today, required: false);
        $draft->phone = $this->phone($draft, StudentImportColumns::PHONE, $value(StudentImportColumns::PHONE));
        $draft->nisn = $this->nisn($draft, $value(StudentImportColumns::NISN));

        // Pendaftaran
        $number = StudentInput::studentNumber(ImportCell::text($value(StudentImportColumns::STUDENT_NUMBER)));

        if ($number !== null && preg_match(StudentInput::STUDENT_NUMBER_PATTERN, $number) !== 1) {
            $draft->error(StudentImportColumns::STUDENT_NUMBER, 'Hanya huruf, angka, titik, garis miring, dan tanda "-", maksimal 30 karakter.');
        } else {
            $draft->studentNumber = $number;
        }

        $draft->startDate = $this->date($draft, StudentImportColumns::START_DATE, $value(StudentImportColumns::START_DATE), $today, required: true);

        $entryType = ImportCell::text($value(StudentImportColumns::ENTRY_TYPE));
        $draft->entryType = $entryType === null ? EntryType::New : EntryType::tryFrom(mb_strtoupper($entryType));

        if ($draft->entryType === null) {
            $draft->error(StudentImportColumns::ENTRY_TYPE, 'Isi BARU atau PINDAHAN.');
        }

        // Kelas (opsional)
        $className = ImportCell::text($value(StudentImportColumns::CLASS_NAME));

        if ($className !== null) {
            $draft->classInput = $className;
            $class = $classes[mb_strtolower((string) preg_replace('/\s+/u', ' ', $className))] ?? null;

            if (! $canManageClasses) {
                $draft->error(StudentImportColumns::CLASS_NAME, 'Anda tidak berhak memasukkan siswa ke kelas di lembaga ini. Kosongkan kolom kelas.');
            } elseif ($class === null) {
                $draft->error(StudentImportColumns::CLASS_NAME, "Kelas \"{$className}\" tidak ada di tahun ajaran berjalan lembaga ini. Lihat lembar Petunjuk.");
            } elseif (! $class->is_active) {
                $draft->error(StudentImportColumns::CLASS_NAME, "Kelas \"{$className}\" nonaktif.");
            } else {
                $draft->class = $class;
            }
        }

        $this->guardians($draft, $value);

        return $draft;
    }

    /**
     * Ayah, ibu, wali (opsional) + kontak utama.
     *
     * @param  callable(string): (bool|DateTimeImmutable|float|int|string|null)  $value
     */
    private function guardians(StudentImportDraft $draft, callable $value): void
    {
        $filled = [];

        foreach (StudentImportColumns::guardians() as $column) {
            $name = StudentInput::name(ImportCell::text($value($column['name'])));
            $phone = $this->phone($draft, $column['phone'], $value($column['phone']));

            if ($name === null) {
                if ($phone !== null) {
                    $draft->error($column['name'], 'Nomor HP diisi, tetapi namanya kosong.');
                }

                continue;
            }

            if (mb_strlen($name) > StudentInput::NAME_MAX_LENGTH) {
                $draft->error($column['name'], 'Maksimal 200 karakter.');

                continue;
            }

            $filled[$column['relation']->value] = new GuardianData(
                relation: $column['relation'],
                name: $name,
                gender: match ($column['relation']) {
                    GuardianRelation::Father => Gender::Male,
                    GuardianRelation::Mother => Gender::Female,
                    default => null,
                },
                phone: $phone,
            );
        }

        $draft->guardians = array_values($filled);
        $primary = ImportCell::text($value(StudentImportColumns::PRIMARY_CONTACT));

        if ($primary === null) {
            $draft->primaryGuardian = $draft->guardians === [] ? null : 0;

            return;
        }

        $relation = GuardianRelation::tryFrom(mb_strtoupper($primary));

        if ($relation === null || $relation === GuardianRelation::Other) {
            $draft->error(StudentImportColumns::PRIMARY_CONTACT, 'Isi AYAH, IBU, atau WALI.');

            return;
        }

        $index = array_search($relation->value, array_keys($filled), true);

        if ($index === false) {
            $draft->error(StudentImportColumns::PRIMARY_CONTACT, 'Nama '.mb_strtolower($relation->label()).' belum diisi.');

            return;
        }

        $draft->primaryGuardian = $index;
    }

    /**
     * @param  CellValue  $raw
     */
    private function limited(StudentImportDraft $draft, string $column, bool|DateTimeImmutable|float|int|string|null $raw, int $max): ?string
    {
        $text = ImportCell::text($raw);

        if ($text !== null && mb_strlen($text) > $max) {
            $draft->error($column, "Maksimal {$max} karakter.");

            return null;
        }

        return $text;
    }

    /**
     * @param  CellValue  $raw
     */
    private function phone(StudentImportDraft $draft, string $column, bool|DateTimeImmutable|float|int|string|null $raw): ?string
    {
        if (ImportCell::isNumber($raw)) {
            $draft->error($column, 'Tersimpan sebagai angka sehingga 0 di depan hilang. Ubah format sel menjadi Teks lalu ketik ulang.');

            return null;
        }

        $phone = StudentInput::phone(ImportCell::text($raw));

        if ($phone !== null && preg_match(StudentInput::PHONE_PATTERN, $phone) !== 1) {
            $draft->error($column, 'Hanya angka (boleh diawali +), 8–19 digit.');

            return null;
        }

        return $phone;
    }

    /**
     * @param  CellValue  $raw
     */
    private function nisn(StudentImportDraft $draft, bool|DateTimeImmutable|float|int|string|null $raw): ?string
    {
        // NISN sering diawali 0: sebagai angka, 0 di depannya hilang.
        if (ImportCell::isNumber($raw)) {
            $draft->error(StudentImportColumns::NISN, 'Tersimpan sebagai angka sehingga 0 di depan bisa hilang. Ubah format sel menjadi Teks lalu ketik ulang.');

            return null;
        }

        $nisn = StudentInput::nisn(ImportCell::text($raw));

        if ($nisn !== null && preg_match(StudentInput::NISN_PATTERN, $nisn) !== 1) {
            $draft->error(StudentImportColumns::NISN, 'Harus 10 angka.');

            return null;
        }

        return $nisn;
    }

    /**
     * @param  CellValue  $raw
     */
    private function date(StudentImportDraft $draft, string $column, bool|DateTimeImmutable|float|int|string|null $raw, string $today, bool $required): ?string
    {
        $date = ImportCell::date($raw);

        if ($date === null) {
            if ($required) {
                $draft->error($column, 'Wajib diisi.');
            }

            return null;
        }

        if ($date === false) {
            $draft->error($column, 'Tanggal tidak valid. Pakai YYYY-MM-DD atau DD/MM/YYYY.');

            return null;
        }

        if ($date > $today) {
            $draft->error($column, 'Tidak boleh di masa depan.');

            return null;
        }

        return $date;
    }

    // ── Antar-baris & database ────────────────────────────────────────────────

    /**
     * NISN: dobel di file → Salah; sudah tercatat untuk nama yang sama → siswa lama; nama lain → Salah.
     *
     * @param  list<StudentImportDraft>  $drafts
     */
    private function checkNisn(string $tenantId, Organization $organization, array $drafts): void
    {
        $nisns = [];

        foreach ($drafts as $draft) {
            if ($draft->nisn !== null) {
                $nisns[] = $draft->nisn;
            }
        }

        /** @var array<string, \stdClass> $owners */
        $owners = [];

        if ($nisns !== []) {
            $rows = DB::table('ac_students as s')
                ->join('memberships as m', 'm.id', '=', 's.membership_id')
                ->join('persons as p', 'p.id', '=', 'm.person_id')
                ->where('s.tenant_id', $tenantId)
                ->whereIn('s.nisn', array_values(array_unique($nisns)))
                ->selectRaw('s.id, s.nisn, s.membership_id, m.status, p.name')
                ->selectRaw('EXISTS (SELECT 1 FROM ac_enrollments e WHERE e.student_id = s.id AND e.organization_id = ? AND e.end_date IS NULL) AS enrolled_here', [$organization->id])
                ->get();

            foreach ($rows as $row) {
                $owners[(string) $row->nisn] = $row;
            }
        }

        /** @var array<string, int> $seen */
        $seen = [];

        foreach ($drafts as $draft) {
            if ($draft->nisn === null) {
                continue;
            }

            if (isset($seen[$draft->nisn])) {
                $draft->error(StudentImportColumns::NISN, 'Sama dengan baris '.$seen[$draft->nisn].'.');

                continue;
            }

            $seen[$draft->nisn] = $draft->line;
            $owner = $owners[$draft->nisn] ?? null;

            if ($owner === null) {
                continue;
            }

            if ($draft->name === null || StudentInput::comparableName((string) $owner->name) !== StudentInput::comparableName($draft->name)) {
                // Nama pemilik NISN tidak disebut agar data siswa lain tidak bocor.
                $draft->error(StudentImportColumns::NISN, 'NISN ini sudah dipakai siswa lain dengan nama berbeda. Periksa lagi NISN atau namanya.');
            } elseif ((string) $owner->status !== MembershipStatus::Active->value) {
                $draft->error(StudentImportColumns::NISN, 'Siswa dengan NISN ini sudah tidak aktif sebagai anggota yayasan.');
            } elseif ((bool) $owner->enrolled_here) {
                $draft->error(StudentImportColumns::NISN, 'Siswa ini sudah terdaftar aktif di lembaga ini.');
            } else {
                $draft->existingMembershipId = (string) $owner->membership_id;
                $draft->existingStudentId = (string) $owner->id;
            }
        }
    }

    /**
     * Nomor induk tidak boleh dipakai siswa LAIN di lembaga ini (§4.2), maupun dobel di dalam file.
     *
     * @param  list<StudentImportDraft>  $drafts
     */
    private function checkStudentNumbers(string $tenantId, Organization $organization, array $drafts): void
    {
        $numbers = [];

        foreach ($drafts as $draft) {
            if ($draft->studentNumber !== null) {
                $numbers[] = $draft->studentNumber;
            }
        }

        /** @var array<string, list<string>> $taken nomor => siswa pemakai */
        $taken = [];

        if ($numbers !== []) {
            $rows = DB::table('ac_enrollments')
                ->where('tenant_id', $tenantId)
                ->where('organization_id', $organization->id)
                ->whereIn('student_number', array_values(array_unique($numbers)))
                ->get(['student_number', 'student_id']);

            foreach ($rows as $row) {
                $taken[(string) $row->student_number][] = (string) $row->student_id;
            }
        }

        /** @var array<string, int> $seen */
        $seen = [];

        foreach ($drafts as $draft) {
            $number = $draft->studentNumber;

            if ($number === null) {
                continue;
            }

            $users = array_diff($taken[$number] ?? [], [$draft->existingStudentId]);

            if ($users !== []) {
                $draft->error(StudentImportColumns::STUDENT_NUMBER, "Nomor induk {$number} sudah dipakai siswa lain di lembaga ini.");
            } elseif (isset($seen[$number])) {
                $draft->error(StudentImportColumns::STUDENT_NUMBER, 'Sama dengan baris '.$seen[$number].'.');
            } else {
                $seen[$number] = $draft->line;
            }
        }
    }

    /**
     * Orang baru dengan nama sama dengan anggota yayasan ini atau baris lain di file → Peringatan
     * (pola §4.5 & HR-1e). Hanya yayasan yang sama; nama dibandingkan tanpa beda huruf & spasi ganda.
     *
     * @param  list<StudentImportDraft>  $drafts
     */
    private function checkSimilarPeople(string $tenantId, array $drafts): void
    {
        $names = [];

        foreach ($drafts as $draft) {
            if ($draft->name !== null && $draft->existingMembershipId === null) {
                $names[] = StudentInput::comparableName($draft->name);
            }
        }

        /** @var array<string, list<string>> $existing nama => keterangan orang */
        $existing = [];

        if ($names !== []) {
            $people = DB::table('persons as p')
                ->join('memberships as m', 'm.person_id', '=', 'p.id')
                ->where('m.tenant_id', $tenantId)
                ->where('m.status', MembershipStatus::Active->value)
                ->whereIn(DB::raw("lower(regexp_replace(trim(p.name), '\\s+', ' ', 'g'))"), array_values(array_unique($names)))
                ->orderBy('p.name')
                ->get(['p.name', 'p.birth_date']);

            foreach ($people as $person) {
                $birthDate = $person->birth_date === null ? null : substr((string) $person->birth_date, 0, 10);
                $existing[StudentInput::comparableName((string) $person->name)][] = (string) $person->name
                    .' ('.($birthDate === null ? 'tanpa tanggal lahir' : 'lahir '.$birthDate).')';
            }
        }

        /** @var array<string, int> $seen */
        $seen = [];

        foreach ($drafts as $draft) {
            if ($draft->name === null) {
                continue;
            }

            $name = StudentInput::comparableName($draft->name);

            if ($draft->existingMembershipId === null && isset($existing[$name])) {
                $draft->warnings[] = 'Nama sama dengan anggota yayasan: '.implode(', ', array_slice($existing[$name], 0, 3))
                    .'. Bila orangnya sama, isi NISN-nya agar hanya didaftarkan; bila berbeda, konfirmasi saat menyimpan.';
            }

            if (isset($seen[$name])) {
                $draft->warnings[] = 'Nama sama dengan baris '.$seen[$name].'.';
            } else {
                $seen[$name] = $draft->line;
            }
        }
    }

    // ── Hasil ─────────────────────────────────────────────────────────────────

    private function toRow(string $tenantId, Organization $organization, StudentImportDraft $draft): StudentImportRow
    {
        return new StudentImportRow(
            line: $draft->line,
            errors: $draft->errors,
            warnings: $draft->warnings,
            mode: $draft->existingMembershipId === null ? StudentImportRow::MODE_NEW : StudentImportRow::MODE_EXISTING,
            name: $draft->name,
            birthDate: $draft->birthDate,
            nisn: $draft->nisn,
            studentNumber: $draft->studentNumber,
            className: $draft->class->name ?? $draft->classInput,
            guardians: count($draft->guardians),
            data: $draft->errors === [] ? $this->toData($tenantId, $organization, $draft) : null,
        );
    }

    /**
     * Isian siap simpan, sama persis dengan form Tambah siswa (dipakai AC-1e-b).
     */
    private function toData(string $tenantId, Organization $organization, StudentImportDraft $draft): ?NewStudentData
    {
        if ($draft->name === null || $draft->gender === null || $draft->startDate === null || $draft->entryType === null) {
            return null;
        }

        return new NewStudentData(
            tenantId: $tenantId,
            name: $draft->name,
            gender: $draft->gender,
            birthPlace: $draft->birthPlace,
            birthDate: $draft->birthDate,
            phone: $draft->phone,
            address: $draft->address,
            nisn: $draft->nisn,
            organizationId: $organization->id,
            studentNumber: $draft->studentNumber,
            startDate: $draft->startDate,
            entryType: $draft->entryType,
            guardians: $draft->guardians,
            primaryGuardian: $draft->primaryGuardian,
            existingMembershipId: $draft->existingMembershipId,
            // Peringatan orang ganda wajib dikonfirmasi sebelum Simpan (pola HR-1e).
            confirmNew: $draft->existingMembershipId === null && $draft->warnings !== [],
            classId: $draft->class?->id,
        );
    }
}
