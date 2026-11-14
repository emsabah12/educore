<?php

namespace Modules\HR\Application\Ptk;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Domain\Ptk\CivilServantStatus;
use Modules\HR\Domain\Ptk\Exceptions\PtkException;
use Modules\HR\Domain\Ptk\PtkFunction;
use Modules\HR\Domain\Ptk\SchoolPosition;

/**
 * Data PTK pegawai untuk Simpatika (PRD-001 §21.1, HR-10a).
 *
 * - Semua isian opsional (kosong = belum diisi). Nama ibu kandung disimpan terenkripsi dan hanya dibaca/diubah bila
 *   pemanggil boleh melihat data pribadi pegawai ini (`$withPersonal`); bila tidak, nilai tersimpan dipertahankan.
 * - Tahun lulus SD 1940 s.d. tahun berjalan, dan tidak sebelum tahun lahir + 5 bila tanggal lahir terisi [ASUMSI].
 * - Fungsi PTK Guru/Staf & jabatan di madrasah/sekolah induk mengikuti formulir A05 (§21.3, OD-HR-28).
 * - Sertifikasi: Belum / Sudah; Sudah → tahun sertifikasi wajib, nomor sertifikat pendidik opsional.
 * - Audit `hr.ptk_profile_updated`: nama isian yang berubah saja, tanpa isi.
 *
 * @phpstan-type PtkValues array{mother_name: string|null, has_mother_name: bool, sd_graduation_year: int|null, ptk_function: string|null, ptk_function_label: string|null, school_position: string|null, school_position_label: string|null, civil_servant: string|null, civil_servant_label: string|null, certified: bool|null, certification_year: int|null, educator_certificate_number: string|null}
 */
final class PtkProfiles
{
    public const YEAR_MIN = 1940;

    public const MOTHER_NAME_MAX = 100;

    public const CERTIFICATE_NUMBER_MAX = 50;

    /** Nama isian di audit & pesan. */
    private const LABELS = [
        'mother_name' => 'nama ibu kandung',
        'sd_graduation_year' => 'tahun lulus SD',
        'ptk_function' => 'fungsi PTK',
        'school_position' => 'jabatan',
        'civil_servant' => 'status PNS',
        'certified' => 'sertifikasi guru',
        'certification_year' => 'tahun sertifikasi',
        'educator_certificate_number' => 'nomor sertifikat pendidik',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Data PTK seorang pegawai. Nama ibu kandung null bila `$withPersonal` false (hanya `has_mother_name`).
     *
     * @return PtkValues
     */
    public function forEmployee(string $tenantId, string $employeeId, bool $withPersonal): array
    {
        $row = Str::isUuid($employeeId)
            ? DB::table('hr_ptk_profiles')->where('tenant_id', $tenantId)->where('employee_id', $employeeId)->first()
            : null;

        $function = $row?->ptk_function === null ? null : PtkFunction::tryFrom((string) $row->ptk_function);
        $civil = $row?->civil_servant === null ? null : CivilServantStatus::tryFrom((string) $row->civil_servant);
        $position = $row?->school_position === null ? null : SchoolPosition::tryFrom((string) $row->school_position);

        return [
            'mother_name' => $withPersonal ? self::decrypt($row?->mother_name_encrypted) : null,
            'has_mother_name' => $row?->mother_name_encrypted !== null,
            'sd_graduation_year' => $row?->sd_graduation_year === null ? null : (int) $row->sd_graduation_year,
            'ptk_function' => $function?->value,
            'ptk_function_label' => $function?->label(),
            'school_position' => $position?->value,
            'school_position_label' => $position?->label(),
            'civil_servant' => $civil?->value,
            'civil_servant_label' => $civil?->label(),
            'certified' => $row?->certified === null ? null : (bool) $row->certified,
            'certification_year' => $row?->certification_year === null ? null : (int) $row->certification_year,
            'educator_certificate_number' => $row?->educator_certificate_number === null ? null : (string) $row->educator_certificate_number,
        ];
    }

    /**
     * @param  array<string, mixed>  $input  isian form (string; kosong = belum diisi)
     *
     * @throws PtkException
     */
    public function update(string $tenantId, string $employeeId, array $input, string $membershipId, bool $withPersonal): void
    {
        $employee = Str::isUuid($employeeId)
            ? DB::table('hr_employees as e')
                ->join('memberships as m', 'm.id', '=', 'e.membership_id')
                ->join('persons as p', 'p.id', '=', 'm.person_id')
                ->where('e.tenant_id', $tenantId)
                ->where('e.id', $employeeId)
                ->first(['e.id', 'p.name', 'p.birth_date'])
            : null;

        if ($employee === null) {
            throw PtkException::make('form', 'Pegawai tidak ditemukan.');
        }

        $values = self::normalize($input, $employee->birth_date === null ? null : (int) substr((string) $employee->birth_date, 0, 4));

        DB::transaction(function () use ($tenantId, $employeeId, $values, $membershipId, $withPersonal, $employee): void {
            $current = DB::table('hr_ptk_profiles')->where('employee_id', $employeeId)->lockForUpdate()->first();
            $currentMother = self::decrypt($current?->mother_name_encrypted);
            $mother = $withPersonal ? $values['mother_name'] : $currentMother;

            $changed = [];

            if ($mother !== $currentMother) {
                $changed[] = self::LABELS['mother_name'];
            }

            foreach (['sd_graduation_year', 'ptk_function', 'school_position', 'civil_servant', 'certified', 'certification_year', 'educator_certificate_number'] as $field) {
                $old = $current?->{$field};
                $old = match ($field) {
                    'sd_graduation_year', 'certification_year' => $old === null ? null : (int) $old,
                    'certified' => $old === null ? null : (bool) $old,
                    default => $old === null ? null : (string) $old,
                };

                if ($old !== $values[$field]) {
                    $changed[] = self::LABELS[$field];
                }
            }

            if ($changed === []) {
                return;
            }

            $row = [
                // Enkripsi ulang hanya bila berubah, supaya isi tersimpan tidak berganti tanpa alasan.
                'mother_name_encrypted' => $mother === $currentMother
                    ? $current?->mother_name_encrypted
                    : ($mother === null ? null : Crypt::encryptString($mother)),
                'sd_graduation_year' => $values['sd_graduation_year'],
                'ptk_function' => $values['ptk_function'],
                'school_position' => $values['school_position'],
                'civil_servant' => $values['civil_servant'],
                'certified' => $values['certified'],
                'certification_year' => $values['certification_year'],
                'educator_certificate_number' => $values['educator_certificate_number'],
                'updated_by_membership_id' => $membershipId,
                'updated_at' => now(),
            ];

            if ($current === null) {
                DB::table('hr_ptk_profiles')->insert($row + [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'employee_id' => $employeeId,
                    'created_at' => now(),
                ]);
            } else {
                DB::table('hr_ptk_profiles')->where('id', $current->id)->update($row);
            }

            $this->audit->record(
                HrAuditAction::PTK_PROFILE_UPDATED,
                HrAuditAction::SUBJECT_EMPLOYEE,
                $employeeId,
                ['nama' => (string) $employee->name, 'isian' => $changed],
                tenantId: $tenantId,
            );
        });
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function functionOptions(): array
    {
        return array_map(fn (PtkFunction $case): array => ['value' => $case->value, 'label' => $case->label()], PtkFunction::cases());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function positionOptions(): array
    {
        return array_map(fn (SchoolPosition $case): array => ['value' => $case->value, 'label' => $case->label()], SchoolPosition::cases());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function civilServantOptions(): array
    {
        return array_map(fn (CivilServantStatus $case): array => ['value' => $case->value, 'label' => $case->label()], CivilServantStatus::cases());
    }

    public static function decrypt(mixed $encrypted): ?string
    {
        if ($encrypted === null || $encrypted === '') {
            return null;
        }

        try {
            return Crypt::decryptString((string) $encrypted);
        } catch (DecryptException) {
            // Kunci aplikasi berganti: anggap belum diisi daripada membuat halaman gagal.
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{mother_name: string|null, sd_graduation_year: int|null, ptk_function: string|null, school_position: string|null, civil_servant: string|null, certified: bool|null, certification_year: int|null, educator_certificate_number: string|null}
     *
     * @throws PtkException
     */
    private static function normalize(array $input, ?int $birthYear): array
    {
        $text = function (string $field) use ($input): ?string {
            $value = $input[$field] ?? null;
            $value = is_scalar($value) ? trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '') : '';

            return $value === '' ? null : $value;
        };
        $thisYear = (int) substr(BusinessClock::todayString(), 0, 4);

        $mother = $text('mother_name');

        if ($mother !== null && mb_strlen($mother) > self::MOTHER_NAME_MAX) {
            throw PtkException::make('mother_name', 'Nama ibu kandung paling panjang '.self::MOTHER_NAME_MAX.' karakter.');
        }

        $sdYear = self::year($text('sd_graduation_year'), 'sd_graduation_year', 'Tahun lulus SD', $thisYear);

        if ($sdYear !== null && $birthYear !== null && $sdYear < $birthYear + 5) {
            throw PtkException::make('sd_graduation_year', "Tahun lulus SD tidak mungkin sebelum {$birthYear} + 5 tahun (tahun lahir pegawai {$birthYear}).");
        }

        $function = $text('ptk_function');

        if ($function !== null && PtkFunction::tryFrom($function) === null) {
            throw PtkException::make('ptk_function', 'Pilih fungsi PTK dari daftar.');
        }

        $position = $text('school_position');

        if ($position !== null && SchoolPosition::tryFrom($position) === null) {
            throw PtkException::make('school_position', 'Pilih jabatan dari daftar.');
        }

        $civil = $text('civil_servant');

        if ($civil !== null && CivilServantStatus::tryFrom($civil) === null) {
            throw PtkException::make('civil_servant', 'Pilih PNS atau Non-PNS.');
        }

        $certifiedRaw = $text('certified');
        $certified = match ($certifiedRaw) {
            null => null,
            'SUDAH' => true,
            'BELUM' => false,
            default => throw PtkException::make('certified', 'Pilih Sudah atau Belum.'),
        };

        $certificationYear = null;
        $certificateNumber = null;

        if ($certified === true) {
            $certificationYear = self::year($text('certification_year'), 'certification_year', 'Tahun sertifikasi', $thisYear);

            if ($certificationYear === null) {
                throw PtkException::make('certification_year', 'Isi tahun sertifikasi.');
            }

            $certificateNumber = $text('educator_certificate_number');

            if ($certificateNumber !== null && mb_strlen($certificateNumber) > self::CERTIFICATE_NUMBER_MAX) {
                throw PtkException::make('educator_certificate_number', 'Nomor sertifikat pendidik paling panjang '.self::CERTIFICATE_NUMBER_MAX.' karakter.');
            }
        }

        return [
            'mother_name' => $mother,
            'sd_graduation_year' => $sdYear,
            'ptk_function' => $function,
            'school_position' => $position,
            'civil_servant' => $civil,
            'certified' => $certified,
            'certification_year' => $certificationYear,
            'educator_certificate_number' => $certificateNumber,
        ];
    }

    /**
     * @throws PtkException
     */
    private static function year(?string $value, string $field, string $label, int $thisYear): ?int
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d{4}$/', $value) !== 1 || (int) $value < self::YEAR_MIN || (int) $value > $thisYear) {
            throw PtkException::make($field, "{$label} harus 4 angka, ".self::YEAR_MIN." s.d. {$thisYear}.");
        }

        return (int) $value;
    }
}
