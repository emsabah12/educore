<?php

namespace Modules\HR\Application\Ptk;

use Illuminate\Support\Facades\DB;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Employee\EmployeeVisibility;
use Modules\HR\Domain\Employee\EducationLevel;
use Modules\HR\Domain\Ptk\SchoolPosition;
use stdClass;

/**
 * Data formulir A05 Registrasi PTK Simpatika (PRD-001 §21.3, HR-10c-2): satu formulir per pegawai aktif yang terlihat
 * DAN boleh dilihat data pribadinya (formulir memuat NIK & nama ibu kandung). Pegawai lain dilewati dan dihitung.
 *
 * - Madrasah/sekolah induk = lembaga penempatan utama; jenjang dipetakan ke kotak A05: SMP/MTs → SMP, MA → SMA,
 *   SMK → SMK; jenjang lain tidak ditandai [ASUMSI].
 * - Pendidikan "Lainnya" tidak ditandai (tidak ada kotaknya di A05).
 * - Nama, tempat lahir, dan nama ibu ditulis huruf besar (formulir meminta huruf balok).
 *
 * @phpstan-type A05Form array{
 *     employee_id: string,
 *     organization: array{id: string|null, name: string|null, npsn: string|null, jenjang: string|null, ownership: string|null, district: string|null, regency: string|null, province: string|null},
 *     nik: string|null, name: string, birth_place: string|null, birth: array{day: string, month: string, year: string}|null,
 *     gender: string|null, mother_name: string|null, education: string|null, sd_graduation_year: string|null,
 *     school_position: string|null, ptk_function: string|null, civil_servant: string|null, certified: bool|null,
 *     missing: list<string>
 * }
 */
final class A05Forms
{
    public const LIMIT = 200;

    /** Kotak jenjang di A05. */
    public const JENJANG_BOXES = ['TK', 'SD', 'SMP', 'SMA', 'SMK', 'SLB'];

    public function __construct(
        private readonly PtkRecap $recap,
        private readonly EmployeeVisibility $visibility,
    ) {}

    /**
     * @return array{forms: list<A05Form>, skipped: int, truncated: bool}
     */
    public function build(?string $organizationId, ?string $employeeId): array
    {
        $records = $this->recap->records($organizationId, $employeeId);
        $ids = array_map(fn (stdClass $record): string => (string) $record->id, $records);

        if ($ids === []) {
            return ['forms' => [], 'skipped' => 0, 'truncated' => false];
        }

        $personalQuery = DB::table('hr_employees as e')->whereIn('e.id', $ids);
        $this->visibility->apply($personalQuery, HrAccess::EMPLOYEES_VIEW_PERSONAL);
        $personal = array_flip($personalQuery->pluck('e.id')->map(fn (mixed $id): string => (string) $id)->all());

        $allowed = array_values(array_filter($records, fn (stdClass $record): bool => isset($personal[(string) $record->id])));
        $truncated = count($allowed) > self::LIMIT;
        $forms = array_map(fn (stdClass $record): array => self::form($record), array_slice($allowed, 0, self::LIMIT));

        return ['forms' => $forms, 'skipped' => count($records) - count($allowed), 'truncated' => $truncated];
    }

    /**
     * @return A05Form
     */
    private static function form(stdClass $record): array
    {
        $upper = fn (mixed $value): ?string => $value === null || ! is_scalar($value) || trim((string) $value) === '' ? null : mb_strtoupper(trim((string) $value));
        $birth = $record->birth_date === null ? null : substr((string) $record->birth_date, 0, 10);
        $education = $record->education_level === null ? null : EducationLevel::tryFrom((string) $record->education_level);
        $position = $record->school_position === null ? null : SchoolPosition::tryFrom((string) $record->school_position);

        $form = [
            'employee_id' => (string) $record->id,
            'organization' => [
                'id' => $record->organization_id === null ? null : (string) $record->organization_id,
                'name' => $record->organization_name === null ? null : (string) $record->organization_name,
                'npsn' => $record->organization_npsn === null ? null : (string) $record->organization_npsn,
                'jenjang' => self::jenjangBox($record->organization_jenjang),
                'ownership' => $record->organization_ownership === null ? null : (string) $record->organization_ownership,
                'district' => $record->organization_district === null ? null : (string) $record->organization_district,
                'regency' => $record->organization_regency === null ? null : (string) $record->organization_regency,
                'province' => $record->organization_province === null ? null : (string) $record->organization_province,
            ],
            'nik' => PtkRecap::decrypt($record->nik_encrypted),
            'name' => (string) $upper($record->name),
            'birth_place' => $upper($record->birth_place),
            'birth' => $birth === null ? null : ['day' => substr($birth, 8, 2), 'month' => substr($birth, 5, 2), 'year' => substr($birth, 0, 4)],
            'gender' => $record->gender === null ? null : (string) $record->gender,
            'mother_name' => $upper(PtkProfiles::decrypt($record->mother_name_encrypted)),
            'education' => $education === null || $education === EducationLevel::Other ? null : $education->value,
            'sd_graduation_year' => $record->sd_graduation_year === null ? null : (string) $record->sd_graduation_year,
            'school_position' => $position?->value,
            'ptk_function' => $record->ptk_function === null ? null : (string) $record->ptk_function,
            'civil_servant' => $record->civil_servant === null ? null : (string) $record->civil_servant,
            'certified' => $record->certified === null ? null : (bool) $record->certified,
            'missing' => [],
        ];

        $checks = [
            'NPSN lembaga' => $form['organization']['npsn'],
            'Status lembaga' => $form['organization']['ownership'],
            'Wilayah lembaga' => $form['organization']['province'],
            'NIK' => $form['nik'],
            'Tempat lahir' => $form['birth_place'],
            'Tanggal lahir' => $form['birth'],
            'Jenis kelamin' => $form['gender'],
            'Nama ibu kandung' => $form['mother_name'],
            'Pendidikan' => $form['education'],
            'Tahun lulus SD' => $form['sd_graduation_year'],
            'Fungsi PTK' => $form['ptk_function'],
            'Status PNS' => $form['civil_servant'],
            'Sertifikasi guru' => $form['certified'],
        ];

        $form['missing'] = array_keys(array_filter($checks, fn (mixed $value): bool => $value === null));

        return $form;
    }

    private static function jenjangBox(mixed $jenjang): ?string
    {
        return match ($jenjang === null ? null : Jenjang::tryFrom((string) $jenjang)) {
            Jenjang::Smp, Jenjang::Mts => 'SMP',
            Jenjang::Ma => 'SMA',
            Jenjang::Smk => 'SMK',
            default => null,
        };
    }
}
