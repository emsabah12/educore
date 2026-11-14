<?php

namespace Modules\HR\Application\Ptk;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Modules\Core\Application\Person\PersonNik;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Organization\OrganizationOwnership;
use Modules\Core\Domain\Person\Gender;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Employee\EmployeeVisibility;
use Modules\HR\Domain\Employee\EducationLevel;
use Modules\HR\Domain\Employee\EmployeeStatus;
use Modules\HR\Domain\Ptk\CivilServantStatus;
use Modules\HR\Domain\Ptk\PtkFunction;
use stdClass;

/**
 * Rekap PTK Simpatika (PRD-001 §21.2, HR-10b): pegawai **aktif** yang terlihat (`hr.employees.view`, cakupan lihat)
 * menurut lembaga **penempatan utama** berjalan, beserta kelengkapan isiannya. Saringan lembaga mencakup turunannya.
 *
 * - Kelengkapan dihitung dari 7 isian: NIK, tanggal lahir, nama ibu kandung, tahun lulus SD, fungsi PTK, status PNS,
 *   sertifikasi. NUPTK hanya ditandai (pegawai baru wajar belum punya). Yang dicek hanya ada/tidaknya, bukan isinya.
 * - Data pribadi (NIK, tempat/tanggal lahir, nama ibu, HP, alamat) hanya dibuka untuk baris yang boleh dilihat
 *   data pribadinya (`hr.employees.view_personal`, OD-HR-25), dan hanya untuk unduhan.
 *
 * @phpstan-type RecapRow array{id: string, name: string, organization_id: string|null, organization: string, missing: list<string>, complete: int, nuptk_missing: bool}
 * @phpstan-type OrganizationRow array{id: string, name: string, employees: int, missing: list<string>}
 */
final class PtkRecap
{
    public const LIMIT = 5000;

    /** Isian yang dihitung untuk kelengkapan (urut tampil). */
    public const CHECKED = ['NIK', 'Tanggal lahir', 'Nama ibu kandung', 'Tahun lulus SD', 'Fungsi PTK', 'Status PNS', 'Sertifikasi guru'];

    public const NO_PRIMARY = 'Tanpa penempatan utama';

    public function __construct(
        private readonly EmployeeVisibility $visibility,
    ) {}

    /**
     * Ringkasan untuk halaman: pegawai (belum lengkap dulu), lembaga dengan data resmi yang belum lengkap.
     *
     * @return array{total: int, complete: int, truncated: bool, rows: list<RecapRow>, organizations: list<OrganizationRow>}
     */
    public function build(?string $organizationId): array
    {
        $records = $this->records($organizationId);
        $truncated = count($records) > self::LIMIT;
        $records = array_slice($records, 0, self::LIMIT);
        $rows = [];
        $organizations = [];

        foreach ($records as $record) {
            $missing = self::missing($record);
            $orgId = $record->organization_id === null ? null : (string) $record->organization_id;

            $rows[] = [
                'id' => (string) $record->id,
                'name' => (string) $record->name,
                'organization_id' => $orgId,
                'organization' => $record->organization_name === null ? self::NO_PRIMARY : (string) $record->organization_name,
                'missing' => $missing,
                'complete' => count(self::CHECKED) - count($missing),
                'nuptk_missing' => $record->nuptk === null,
            ];

            if ($orgId !== null) {
                $organizations[$orgId] ??= ['id' => $orgId, 'name' => (string) $record->organization_name, 'employees' => 0, 'missing' => self::organizationMissing($record)];
                $organizations[$orgId]['employees']++;
            }
        }

        // Belum lengkap dulu (paling banyak kurangnya), lalu lembaga & nama.
        usort($rows, fn (array $a, array $b): int => [$a['complete'], $a['organization'], $a['name']] <=> [$b['complete'], $b['organization'], $b['name']]);
        usort($organizations, fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return [
            'total' => count($rows),
            'complete' => count(array_filter($rows, fn (array $row): bool => $row['missing'] === [])),
            'truncated' => $truncated,
            'rows' => $rows,
            'organizations' => $organizations,
        ];
    }

    /**
     * Baris lengkap untuk unduhan .xlsx (urut lembaga lalu nama).
     *
     * @return array{rows: list<array<string, string|null>>, missing: list<array{organization: string, name: string, missing: string}>, truncated: bool, personal_count: int}
     */
    public function export(?string $organizationId): array
    {
        $records = $this->records($organizationId);
        $truncated = count($records) > self::LIMIT;
        $records = array_slice($records, 0, self::LIMIT);
        $ids = array_map(fn (stdClass $record): string => (string) $record->id, $records);

        if ($ids === []) {
            return ['rows' => [], 'missing' => [], 'truncated' => false, 'personal_count' => 0];
        }

        $personalQuery = DB::table('hr_employees as e')->whereIn('e.id', $ids);
        $this->visibility->apply($personalQuery, HrAccess::EMPLOYEES_VIEW_PERSONAL);
        $personal = array_flip($personalQuery->pluck('e.id')->map(fn (mixed $id): string => (string) $id)->all());

        $rows = [];
        $missingRows = [];

        foreach ($records as $record) {
            $id = (string) $record->id;
            $sees = isset($personal[$id]);
            $function = $record->ptk_function === null ? null : PtkFunction::tryFrom((string) $record->ptk_function);
            $civil = $record->civil_servant === null ? null : CivilServantStatus::tryFrom((string) $record->civil_servant);
            $jenjang = $record->organization_jenjang === null ? null : Jenjang::tryFrom((string) $record->organization_jenjang);
            $ownership = $record->organization_ownership === null ? null : OrganizationOwnership::tryFrom((string) $record->organization_ownership);
            $organization = $record->organization_name === null ? self::NO_PRIMARY : (string) $record->organization_name;

            $rows[] = [
                'organization' => $organization,
                'npsn' => self::text($record->organization_npsn),
                'jenjang' => $jenjang?->label(),
                'ownership' => $ownership?->label(),
                'district' => self::text($record->organization_district),
                'regency' => self::text($record->organization_regency),
                'province' => self::text($record->organization_province),
                'name' => (string) $record->name,
                'nik' => $sees ? self::decrypt($record->nik_encrypted) : null,
                'nuptk' => self::text($record->nuptk),
                'gender' => $record->gender === null ? null : Gender::tryFrom((string) $record->gender)?->label(),
                'birth_place' => $sees ? self::text($record->birth_place) : null,
                'birth_date' => $sees && $record->birth_date !== null ? substr((string) $record->birth_date, 0, 10) : null,
                'mother_name' => $sees ? PtkProfiles::decrypt($record->mother_name_encrypted) : null,
                'education' => $record->education_level === null ? null : EducationLevel::tryFrom((string) $record->education_level)?->label(),
                'education_major' => self::text($record->education_major),
                'sd_graduation_year' => self::text($record->sd_graduation_year),
                'position' => self::text($record->position_name),
                'ptk_function' => $function?->label(),
                'civil_servant' => $civil?->label(),
                'employment_type' => self::text($record->employment_type),
                'classification' => self::text($record->classification),
                'start_date' => $record->first_start === null ? null : substr((string) $record->first_start, 0, 10),
                'certified' => $record->certified === null ? null : ((bool) $record->certified ? 'Sudah' : 'Belum'),
                'certification_year' => self::text($record->certification_year),
                'certificate_number' => self::text($record->educator_certificate_number),
                'phone' => $sees ? self::text($record->phone) : null,
                'address' => $sees ? self::text($record->address) : null,
            ];

            $missing = self::missing($record);

            if ($record->nuptk === null) {
                $missing[] = 'NUPTK (belum ada)';
            }

            if ($missing !== []) {
                $missingRows[] = ['organization' => $organization, 'name' => (string) $record->name, 'missing' => implode(', ', $missing)];
            }
        }

        return ['rows' => $rows, 'missing' => $missingRows, 'truncated' => $truncated, 'personal_count' => count($personal)];
    }

    /**
     * Isian kelengkapan yang masih kosong.
     *
     * @return list<string>
     */
    private static function missing(stdClass $record): array
    {
        $present = [
            'NIK' => $record->nik_encrypted !== null,
            'Tanggal lahir' => $record->birth_date !== null,
            'Nama ibu kandung' => $record->mother_name_encrypted !== null,
            'Tahun lulus SD' => $record->sd_graduation_year !== null,
            'Fungsi PTK' => $record->ptk_function !== null,
            'Status PNS' => $record->civil_servant !== null,
            'Sertifikasi guru' => $record->certified !== null,
        ];

        return array_keys(array_filter($present, fn (bool $filled): bool => ! $filled));
    }

    /**
     * @return list<string>
     */
    private static function organizationMissing(stdClass $record): array
    {
        $missing = [];

        foreach (['organization_npsn' => 'NPSN', 'organization_ownership' => 'status', 'organization_district' => 'kecamatan', 'organization_regency' => 'kab/kota', 'organization_province' => 'provinsi'] as $column => $label) {
            if ($record->{$column} === null) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    /**
     * Pegawai aktif terlihat + penempatan utama berjalan + Data PTK + periode terakhir. Urut lembaga, nama.
     *
     * @return list<stdClass>
     */
    private function records(?string $organizationId): array
    {
        $query = DB::table('hr_employees as e')
            ->join('memberships as m', 'm.id', '=', 'e.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->leftJoin('hr_placements as pp', function ($join): void {
                $join->on('pp.employee_id', '=', 'e.id')->where('pp.is_primary', true)->whereNull('pp.end_date');
            })
            ->leftJoin('organizations as o', 'o.id', '=', 'pp.organization_id')
            ->leftJoin('hr_positions as j', 'j.id', '=', 'pp.position_id')
            ->leftJoin('hr_ptk_profiles as k', 'k.employee_id', '=', 'e.id')
            ->leftJoin('person_identifiers as pi', function ($join): void {
                $join->on('pi.person_id', '=', 'p.id')->where('pi.type', PersonNik::TYPE);
            })
            ->where('e.status', EmployeeStatus::Active->value);

        $this->visibility->apply($query, HrAccess::EMPLOYEES_VIEW);

        if ($organizationId !== null) {
            $query->whereIn('pp.organization_id', fn (Builder $closure) => $closure->select('descendant_id')->from('organization_closure')->where('ancestor_id', $organizationId));
        }

        $records = $query
            ->orderByRaw('o.name NULLS LAST')
            ->orderBy('p.name')
            ->orderBy('e.id')
            ->limit(self::LIMIT + 1)
            ->get([
                'e.id', 'e.nuptk', 'e.education_level', 'e.education_major',
                'p.name', 'p.gender', 'p.birth_place', 'p.birth_date', 'p.phone', 'p.address',
                'pi.encrypted_value as nik_encrypted',
                'o.id as organization_id', 'o.name as organization_name', 'o.jenjang as organization_jenjang', 'o.npsn as organization_npsn',
                'o.ownership as organization_ownership', 'o.district as organization_district', 'o.regency as organization_regency', 'o.province as organization_province',
                'j.name as position_name',
                'k.mother_name_encrypted', 'k.sd_graduation_year', 'k.ptk_function', 'k.civil_servant', 'k.certified', 'k.certification_year', 'k.educator_certificate_number',
            ])
            ->values()
            ->all();

        $ids = array_map(fn (stdClass $record): string => (string) $record->id, $records);
        $periods = [];

        // Periode terakhir (jenis & status kepegawaian) dan tanggal mulai kerja pertama.
        foreach ($ids === [] ? [] : DB::table('hr_employment_periods as ep')
            ->join('hr_employment_types as t', 't.id', '=', 'ep.employment_type_id')
            ->leftJoin('hr_employment_classifications as c', 'c.id', '=', 'ep.classification_id')
            ->whereIn('ep.employee_id', $ids)
            ->orderBy('ep.start_date')
            ->get(['ep.employee_id', 'ep.start_date', 't.name as type_name', 'c.name as classification_name']) as $period) {
            $id = (string) $period->employee_id;
            $periods[$id]['first'] ??= (string) $period->start_date;
            $periods[$id]['type'] = (string) $period->type_name;
            $periods[$id]['classification'] = $period->classification_name === null ? null : (string) $period->classification_name;
        }

        foreach ($records as $record) {
            $period = $periods[(string) $record->id] ?? [];
            $record->first_start = $period['first'] ?? null;
            $record->employment_type = $period['type'] ?? null;
            $record->classification = $period['classification'] ?? null;
        }

        return array_values($records);
    }

    private static function text(mixed $value): ?string
    {
        return $value === null || ! is_scalar($value) ? null : (string) $value;
    }

    private static function decrypt(mixed $encrypted): ?string
    {
        if ($encrypted === null || ! is_scalar($encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString((string) $encrypted);
        } catch (DecryptException) {
            return null;
        }
    }
}
