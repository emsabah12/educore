<?php

namespace Modules\HR\Application\Import;

use DateInterval;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Employee\NewEmployeeData;
use Modules\HR\Application\Employee\PersonalData;
use Modules\HR\Domain\Catalog\CatalogItem;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Employee\EducationLevel;
use Modules\HR\Domain\Employee\Employee;

/**
 * Memeriksa baris file impor pegawai tanpa menyimpan apa pun (PRD-001 §6.2).
 *
 * Aturan isian sama dengan form Tambah pegawai mode orang baru (§4.3–§4.5). Tambahan khusus impor:
 * kode katalog/lembaga, nomor induk & NUPTK dobel di dalam file, dan NUPTK/HP yang tersimpan
 * sebagai angka (0 di depan bisa hilang) ditolak. Orang ganda hanya menjadi peringatan (OD-HR-05).
 */
final class EmployeeImportValidator
{
    private const NAME_MAX = 200;

    private const BIRTH_PLACE_MAX = 100;

    private const ADDRESS_MAX = 500;

    private const MAJOR_MAX = 150;

    /** Rentang nomor seri tanggal Excel yang masuk akal (1900-01-01 s.d. 9999-12-31). */
    private const EXCEL_SERIAL_MAX = 2958465;

    /** @var array<string, array<string, CatalogItem>> jenis katalog => KODE => item */
    private array $catalogs = [];

    /** @var array<string, Organization> KODE => lembaga */
    private array $organizations = [];

    /**
     * @param  list<array{line: int, values: array<string, bool|DateTimeImmutable|float|int|string|null>}>  $rows
     */
    public function validate(string $tenantId, array $rows): EmployeeImportPreview
    {
        $this->loadLookups($tenantId);
        $today = BusinessClock::todayString();

        $drafts = [];

        foreach ($rows as $row) {
            $drafts[] = $this->parse($row['line'], $row['values'], $today);
        }

        $this->checkNumbers($tenantId, $drafts);
        $this->checkSimilarPeople($tenantId, $drafts);

        return new EmployeeImportPreview(array_map(fn (ImportRowDraft $draft): EmployeeImportRow => $this->toRow($tenantId, $draft), $drafts));
    }

    // ── Satu baris ────────────────────────────────────────────────────────────

    /**
     * @param  array<string, bool|DateTimeImmutable|float|int|string|null>  $values
     */
    private function parse(int $line, array $values, string $today): ImportRowDraft
    {
        $draft = new ImportRowDraft($line);
        $value = fn (string $key): bool|DateTimeImmutable|float|int|string|null => $values[$key] ?? null;

        // Nama & jenis kelamin
        $draft->name = $this->text($value(EmployeeImportColumns::NAME));

        if ($draft->name === null) {
            $draft->error(EmployeeImportColumns::NAME, 'Wajib diisi.');
        } elseif (mb_strlen($draft->name) > self::NAME_MAX) {
            $draft->error(EmployeeImportColumns::NAME, 'Maksimal 200 karakter.');
        }

        $gender = $this->text($value(EmployeeImportColumns::GENDER));
        $draft->gender = $gender === null ? null : Gender::tryFrom(mb_strtoupper($gender));

        if ($draft->gender === null) {
            $draft->error(EmployeeImportColumns::GENDER, 'Isi L atau P.');
        }

        // Data pribadi (opsional)
        $draft->birthPlace = $this->limited($draft, EmployeeImportColumns::BIRTH_PLACE, $value(EmployeeImportColumns::BIRTH_PLACE), self::BIRTH_PLACE_MAX);
        $draft->address = $this->limited($draft, EmployeeImportColumns::ADDRESS, $value(EmployeeImportColumns::ADDRESS), self::ADDRESS_MAX);
        $draft->birthDate = $this->date($draft, EmployeeImportColumns::BIRTH_DATE, $value(EmployeeImportColumns::BIRTH_DATE), $today, required: false);
        $draft->phone = $this->phone($draft, $value(EmployeeImportColumns::PHONE));

        // Data kepegawaian
        $draft->employeeNumber = $this->employeeNumber($draft, $value(EmployeeImportColumns::EMPLOYEE_NUMBER));
        $draft->nuptk = $this->nuptk($draft, $value(EmployeeImportColumns::NUPTK));
        $draft->educationLevel = $this->education($draft, $value(EmployeeImportColumns::EDUCATION));
        $draft->educationMajor = $this->limited($draft, EmployeeImportColumns::MAJOR, $value(EmployeeImportColumns::MAJOR), self::MAJOR_MAX);

        $draft->employmentTypeInput = $this->text($value(EmployeeImportColumns::EMPLOYMENT_TYPE));
        $draft->employmentType = $this->catalogItem($draft, CatalogKind::EmploymentType, EmployeeImportColumns::EMPLOYMENT_TYPE, $draft->employmentTypeInput, required: true);
        $draft->classification = $this->catalogItem($draft, CatalogKind::Classification, EmployeeImportColumns::CLASSIFICATION, $this->text($value(EmployeeImportColumns::CLASSIFICATION)), required: false);
        $draft->startDate = $this->date($draft, EmployeeImportColumns::START_DATE, $value(EmployeeImportColumns::START_DATE), $today, required: true);

        $draft->organizationInput = $this->text($value(EmployeeImportColumns::ORGANIZATION));
        $draft->organization = $this->organization($draft, $draft->organizationInput);
        $draft->positionInput = $this->text($value(EmployeeImportColumns::POSITION));
        $draft->position = $this->catalogItem($draft, CatalogKind::Position, EmployeeImportColumns::POSITION, $draft->positionInput, required: true);

        return $draft;
    }

    private function limited(ImportRowDraft $draft, string $column, bool|DateTimeImmutable|float|int|string|null $raw, int $max): ?string
    {
        $text = $this->text($raw);

        if ($text !== null && mb_strlen($text) > $max) {
            $draft->error($column, "Maksimal {$max} karakter.");

            return null;
        }

        return $text;
    }

    private function phone(ImportRowDraft $draft, bool|DateTimeImmutable|float|int|string|null $raw): ?string
    {
        if (is_int($raw) || is_float($raw)) {
            $draft->error(EmployeeImportColumns::PHONE, 'Tersimpan sebagai angka sehingga 0 di depan hilang. Ubah format sel menjadi Teks lalu ketik ulang.');

            return null;
        }

        $phone = PersonalData::phone($this->text($raw));

        if ($phone !== null && preg_match(PersonalData::PHONE_PATTERN, $phone) !== 1) {
            $draft->error(EmployeeImportColumns::PHONE, 'Hanya angka (boleh diawali +), 8–19 digit.');

            return null;
        }

        return $phone;
    }

    private function employeeNumber(ImportRowDraft $draft, bool|DateTimeImmutable|float|int|string|null $raw): ?string
    {
        $number = PersonalData::employeeNumber($this->text($raw));

        if ($number !== null && preg_match(PersonalData::EMPLOYEE_NUMBER_PATTERN, $number) !== 1) {
            $draft->error(EmployeeImportColumns::EMPLOYEE_NUMBER, 'Hanya huruf, angka, titik, garis miring, dan tanda "-", maksimal 30 karakter.');

            return null;
        }

        return $number;
    }

    private function nuptk(ImportRowDraft $draft, bool|DateTimeImmutable|float|int|string|null $raw): ?string
    {
        // Angka 16 digit melebihi ketelitian angka Excel: digit terakhir bisa berubah tanpa terlihat.
        if (is_int($raw) || is_float($raw)) {
            $draft->error(EmployeeImportColumns::NUPTK, 'Tersimpan sebagai angka sehingga digitnya bisa berubah. Ubah format sel menjadi Teks lalu ketik ulang.');

            return null;
        }

        $nuptk = PersonalData::nuptk($this->text($raw));

        if ($nuptk !== null && preg_match(PersonalData::NUPTK_PATTERN, $nuptk) !== 1) {
            $draft->error(EmployeeImportColumns::NUPTK, 'Harus 16 angka.');

            return null;
        }

        return $nuptk;
    }

    private function education(ImportRowDraft $draft, bool|DateTimeImmutable|float|int|string|null $raw): ?EducationLevel
    {
        $code = $this->text($raw);

        if ($code === null) {
            return null;
        }

        $level = EducationLevel::tryFrom(mb_strtoupper($code));

        if ($level === null) {
            $draft->error(EmployeeImportColumns::EDUCATION, "Kode \"{$code}\" tidak dikenal. Lihat lembar Kode.");
        }

        return $level;
    }

    private function catalogItem(ImportRowDraft $draft, CatalogKind $kind, string $column, ?string $code, bool $required): ?CatalogItem
    {
        if ($code === null) {
            if ($required) {
                $draft->error($column, 'Wajib diisi.');
            }

            return null;
        }

        $item = $this->catalogs[$kind->value][mb_strtoupper($code)] ?? null;

        if ($item === null) {
            $draft->error($column, "Kode \"{$code}\" tidak ada di katalog. Lihat lembar Kode.");

            return null;
        }

        if (! $item->is_active) {
            $draft->error($column, "Kode \"{$code}\" sudah nonaktif.");

            return null;
        }

        return $item;
    }

    private function organization(ImportRowDraft $draft, ?string $code): ?Organization
    {
        if ($code === null) {
            $draft->error(EmployeeImportColumns::ORGANIZATION, 'Wajib diisi.');

            return null;
        }

        $organization = $this->organizations[mb_strtoupper($code)] ?? null;

        if ($organization === null) {
            $draft->error(EmployeeImportColumns::ORGANIZATION, "Kode lembaga \"{$code}\" tidak ada di yayasan ini. Lihat lembar Kode.");

            return null;
        }

        if (! $organization->isActive()) {
            $draft->error(EmployeeImportColumns::ORGANIZATION, "Lembaga \"{$code}\" sudah nonaktif.");

            return null;
        }

        return $organization;
    }

    /**
     * Sel tanggal Excel, nomor seri tanggal Excel, "YYYY-MM-DD", atau "DD/MM/YYYY" → "YYYY-MM-DD".
     */
    private function date(ImportRowDraft $draft, string $column, bool|DateTimeImmutable|float|int|string|null $raw, string $today, bool $required): ?string
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            if ($required) {
                $draft->error($column, 'Wajib diisi.');
            }

            return null;
        }

        $date = null;

        if ($raw instanceof DateTimeImmutable) {
            $date = $raw->format('Y-m-d');
        } elseif (is_int($raw) || is_float($raw)) {
            $date = $this->fromExcelSerial($raw);
        } elseif (is_string($raw)) {
            $date = $this->fromText(trim($raw));
        }

        if ($date === null) {
            $draft->error($column, 'Tanggal tidak valid. Pakai YYYY-MM-DD atau DD/MM/YYYY.');

            return null;
        }

        if ($date > $today) {
            $draft->error($column, 'Tidak boleh di masa depan.');

            return null;
        }

        return $date;
    }

    private function fromExcelSerial(float|int $serial): ?string
    {
        if ($serial < 1 || $serial > self::EXCEL_SERIAL_MAX) {
            return null;
        }

        // Excel menghitung hari sejak 1899-12-30 (termasuk "29 Februari 1900" yang keliru).
        return (new DateTimeImmutable('1899-12-30'))->add(new DateInterval('P'.(int) floor($serial).'D'))->format('Y-m-d');
    }

    private function fromText(string $text): ?string
    {
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $text, $parts) === 1) {
            [$year, $month, $day] = [(int) $parts[1], (int) $parts[2], (int) $parts[3]];
        } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $text, $parts) === 1) {
            [$day, $month, $year] = [(int) $parts[1], (int) $parts[2], (int) $parts[3]];
        } else {
            return null;
        }

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * Isian sel menjadi teks yang sudah dipangkas; kosong = null.
     */
    private function text(bool|DateTimeImmutable|float|int|string|null $raw): ?string
    {
        if (is_string($raw)) {
            return PersonalData::text($raw);
        }

        if (is_int($raw)) {
            return (string) $raw;
        }

        if (is_float($raw)) {
            return floor($raw) === $raw ? sprintf('%.0f', $raw) : (string) $raw;
        }

        if ($raw instanceof DateTimeImmutable) {
            return $raw->format('Y-m-d');
        }

        if (is_bool($raw)) {
            return $raw ? 'TRUE' : 'FALSE';
        }

        return null;
    }

    // ── Antar-baris & database ────────────────────────────────────────────────

    /**
     * Nomor induk & NUPTK tidak boleh dipakai pegawai lain di yayasan ini, maupun dobel di dalam file.
     *
     * @param  list<ImportRowDraft>  $drafts
     */
    private function checkNumbers(string $tenantId, array $drafts): void
    {
        $numbers = $this->filled(array_map(fn (ImportRowDraft $draft): ?string => $draft->employeeNumber, $drafts));
        $nuptks = $this->filled(array_map(fn (ImportRowDraft $draft): ?string => $draft->nuptk, $drafts));

        $employees = Employee::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId);
        $takenNumbers = $numbers === [] ? [] : array_flip((clone $employees)->whereIn('employee_number', $numbers)->pluck('employee_number')->map(fn (mixed $value): string => (string) $value)->all());
        $takenNuptks = $nuptks === [] ? [] : array_flip((clone $employees)->whereIn('nuptk', $nuptks)->pluck('nuptk')->map(fn (mixed $value): string => (string) $value)->all());

        /** @var array<string, int> $seenNumbers */
        $seenNumbers = [];
        /** @var array<string, int> $seenNuptks */
        $seenNuptks = [];

        foreach ($drafts as $draft) {
            if ($draft->employeeNumber !== null) {
                if (isset($takenNumbers[$draft->employeeNumber])) {
                    $draft->error(EmployeeImportColumns::EMPLOYEE_NUMBER, "Nomor induk {$draft->employeeNumber} sudah dipakai pegawai lain.");
                } elseif (isset($seenNumbers[$draft->employeeNumber])) {
                    $draft->error(EmployeeImportColumns::EMPLOYEE_NUMBER, 'Sama dengan baris '.$seenNumbers[$draft->employeeNumber].'.');
                } else {
                    $seenNumbers[$draft->employeeNumber] = $draft->line;
                }
            }

            if ($draft->nuptk !== null) {
                if (isset($takenNuptks[$draft->nuptk])) {
                    $draft->error(EmployeeImportColumns::NUPTK, 'NUPTK sudah dipakai pegawai lain.');
                } elseif (isset($seenNuptks[$draft->nuptk])) {
                    $draft->error(EmployeeImportColumns::NUPTK, 'Sama dengan baris '.$seenNuptks[$draft->nuptk].'.');
                } else {
                    $seenNuptks[$draft->nuptk] = $draft->line;
                }
            }
        }
    }

    /**
     * Peringatan orang ganda (PRD-001 §6.2), hanya di yayasan yang sama:
     *
     * - nama + tanggal lahir sama dengan anggota yayasan atau baris lain di file;
     * - [ASUMSI, temuan uji owner 2026-10-17] NAMA saja sama, dengan tanggal lahir orang lama
     *   ditampilkan. Menangkap tanggal yang terbaca beda format di Excel (12/04 ↔ 04/12), data
     *   lama tanpa tanggal lahir, dan file yang sama diunggah dua kali.
     *
     * Nama dibandingkan tanpa beda huruf besar/kecil dan spasi ganda.
     *
     * @param  list<ImportRowDraft>  $drafts
     */
    private function checkSimilarPeople(string $tenantId, array $drafts): void
    {
        $names = array_values(array_unique($this->filled(array_map(
            fn (ImportRowDraft $draft): ?string => $draft->name === null ? null : $this->normalizeName($draft->name),
            $drafts,
        ))));

        /** @var array<string, list<array{label: string, birthDate: string|null}>> $existing nama => anggota */
        $existing = [];

        if ($names !== []) {
            $people = DB::table('persons as p')
                ->join('memberships as m', 'm.person_id', '=', 'p.id')
                ->leftJoin('hr_employees as e', 'e.membership_id', '=', 'm.id')
                ->where('m.tenant_id', $tenantId)
                ->whereIn(DB::raw("lower(regexp_replace(trim(p.name), '\\s+', ' ', 'g'))"), $names)
                ->orderBy('p.name')
                ->get(['p.name', 'p.birth_date', 'e.id as employee_id']);

            foreach ($people as $person) {
                $birthDate = $person->birth_date === null ? null : substr((string) $person->birth_date, 0, 10);
                $label = (string) $person->name.' ('.($person->employee_id === null ? 'anggota' : 'pegawai')
                    .', '.($birthDate === null ? 'tanpa tanggal lahir' : 'lahir '.$birthDate).')';

                $existing[$this->normalizeName((string) $person->name)][] = ['label' => $label, 'birthDate' => $birthDate];
            }
        }

        /** @var array<string, list<array{line: int, birthDate: string|null}>> $seen nama => baris sebelumnya */
        $seen = [];

        foreach ($drafts as $draft) {
            if ($draft->name === null) {
                continue;
            }

            $name = $this->normalizeName($draft->name);

            if (isset($existing[$name])) {
                $sameBirth = array_filter($existing[$name], fn (array $person): bool => $draft->birthDate !== null && $person['birthDate'] === $draft->birthDate);
                $labels = implode(', ', array_slice(array_map(fn (array $person): string => $person['label'], $existing[$name]), 0, 3));

                $draft->warnings[] = $sameBirth !== []
                    ? "Nama dan tanggal lahir sama dengan anggota yayasan: {$labels}."
                    : "Nama sama dengan anggota yayasan: {$labels}. Pastikan bukan orang yang sama.";
            }

            if (isset($seen[$name])) {
                $first = $seen[$name][0];
                $draft->warnings[] = $draft->birthDate !== null && $first['birthDate'] === $draft->birthDate
                    ? 'Nama dan tanggal lahir sama dengan baris '.$first['line'].'.'
                    : 'Nama sama dengan baris '.$first['line'].'.';
            }

            $seen[$name][] = ['line' => $draft->line, 'birthDate' => $draft->birthDate];
        }
    }

    private function normalizeName(string $name): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($name)));
    }

    /**
     * @param  list<string|null>  $values
     * @return list<string>
     */
    private function filled(array $values): array
    {
        $filled = [];

        foreach ($values as $value) {
            if ($value !== null) {
                $filled[] = $value;
            }
        }

        return $filled;
    }

    // ── Hasil ─────────────────────────────────────────────────────────────────

    private function toRow(string $tenantId, ImportRowDraft $draft): EmployeeImportRow
    {
        return new EmployeeImportRow(
            line: $draft->line,
            errors: $draft->errors,
            warnings: $draft->warnings,
            name: $draft->name,
            birthDate: $draft->birthDate,
            organization: $draft->organization === null
                ? $draft->organizationInput
                : "{$draft->organization->name} ({$draft->organization->code})",
            position: $draft->position->name ?? $draft->positionInput,
            employmentType: $draft->employmentType->name ?? $draft->employmentTypeInput,
            data: $draft->errors === [] ? $this->toData($tenantId, $draft) : null,
        );
    }

    /**
     * Isian siap simpan, sama persis dengan form Tambah pegawai mode orang baru.
     */
    private function toData(string $tenantId, ImportRowDraft $draft): ?NewEmployeeData
    {
        if ($draft->name === null || $draft->gender === null || $draft->employmentType === null
            || $draft->startDate === null || $draft->organization === null || $draft->position === null) {
            return null;
        }

        return new NewEmployeeData(
            tenantId: $tenantId,
            membershipId: null,
            name: $draft->name,
            gender: $draft->gender,
            birthPlace: $draft->birthPlace,
            birthDate: $draft->birthDate,
            phone: $draft->phone,
            address: $draft->address,
            employeeNumber: $draft->employeeNumber,
            nuptk: $draft->nuptk,
            educationLevel: $draft->educationLevel,
            educationMajor: $draft->educationMajor,
            employmentTypeId: $draft->employmentType->id,
            classificationId: $draft->classification?->id,
            startDate: $draft->startDate,
            organizationId: $draft->organization->id,
            positionId: $draft->position->id,
            // Peringatan orang ganda sudah dikonfirmasi admin sebelum Simpan (OD-HR-05).
            confirmDuplicate: $draft->warnings !== [],
        );
    }

    // ── Rujukan ───────────────────────────────────────────────────────────────

    private function loadLookups(string $tenantId): void
    {
        $this->catalogs = [];

        foreach (CatalogKind::cases() as $kind) {
            $items = [];

            foreach ($kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->get() as $item) {
                $items[mb_strtoupper($item->code)] = $item;
            }

            $this->catalogs[$kind->value] = $items;
        }

        $this->organizations = [];

        foreach (Organization::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->get() as $organization) {
            $this->organizations[mb_strtoupper($organization->code)] = $organization;
        }
    }
}
