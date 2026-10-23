<?php

namespace Modules\HR\Application\Employee;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Application\Person\PersonNik;
use Modules\Core\Domain\Person\Exceptions\NikTakenException;
use Modules\Core\Domain\Person\Person;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Domain\Employee\Employee;
use Modules\HR\Domain\Employee\Exceptions\EmployeeException;

/**
 * Ubah profil pegawai (PRD-001 §6): data diri di Person + data kepegawaian di Pegawai.
 *
 * [ASUMSI, disetujui owner 2026-10-13] Nama boleh diubah di sini (mis. salah ketik). Nama milik
 * Person, jadi bila pegawai punya akun login, nama di akunnya ikut berubah.
 *
 * Audit hanya mencatat NAMA field yang berubah, bukan nilainya (PRD-001 §7).
 */
final class UpdateEmployeeProfile
{
    private const NAME_MAX_LENGTH = 200;

    public function __construct(
        private readonly EmployeeGuard $guard,
        private readonly AuditLogger $audit,
        private readonly PersonNik $personNik,
        private readonly EmployeeNik $employeeNik,
    ) {}

    /**
     * @throws EmployeeException
     */
    public function handle(string $tenantId, string $employeeId, EmployeeProfileData $data): Employee
    {
        $this->assertValidInput($data);

        try {
            return DB::transaction(function () use ($tenantId, $employeeId, $data): Employee {
                $employee = $this->guard->lockEmployee($tenantId, $employeeId);
                $membership = Membership::query()->whereKey($employee->membership_id)->firstOrFail();
                $person = Person::query()->whereKey($membership->person_id)->lockForUpdate()->firstOrFail();

                $this->assertNumbersAvailable($tenantId, $employee->id, $data);

                $person->fill([
                    'name' => $data->name,
                    'gender' => $data->gender,
                    'birth_place' => $data->birthPlace,
                    'birth_date' => $data->birthDate,
                    'phone' => $data->phone,
                    'address' => $data->address,
                ]);
                $employee->fill([
                    'employee_number' => $data->employeeNumber,
                    'nuptk' => $data->nuptk,
                    'education_level' => $data->educationLevel,
                    'education_major' => $data->educationMajor,
                ]);

                $changed = array_values(array_unique(array_merge(
                    array_keys($person->getDirty()),
                    array_keys($employee->getDirty()),
                )));

                if ($data->nik === null && $data->nikSubmitted && $this->personNik->valueOf($person->id) !== null) {
                    throw EmployeeException::nikCannotBeRemoved();
                }

                // Hanya nama field yang dicatat di audit, bukan nilai NIK.
                if ($data->nik !== null && $this->personNik->assign($person->id, $data->nik)) {
                    $changed[] = 'nik';
                }

                if ($changed === []) {
                    return $employee;
                }

                $person->save();
                $employee->save();

                $this->audit->record(
                    HrAuditAction::EMPLOYEE_UPDATED,
                    HrAuditAction::SUBJECT_EMPLOYEE,
                    $employee->id,
                    ['nama' => $person->name, 'field_diubah' => $changed],
                    tenantId: $tenantId,
                );

                return $employee;
            });
        } catch (NikTakenException $exception) {
            throw EmployeeException::nikTaken($this->employeeNik->takenMessage($tenantId, $exception->ownerPersonId));
        } catch (UniqueConstraintViolationException $exception) {
            throw str_contains($exception->getMessage(), 'nuptk')
                ? EmployeeException::duplicateNuptk()
                : EmployeeException::duplicateEmployeeNumber($data->employeeNumber ?? '-');
        }
    }

    private function assertNumbersAvailable(string $tenantId, string $employeeId, EmployeeProfileData $data): void
    {
        $others = Employee::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKeyNot($employeeId);

        if ($data->employeeNumber !== null && (clone $others)->where('employee_number', $data->employeeNumber)->exists()) {
            throw EmployeeException::duplicateEmployeeNumber($data->employeeNumber);
        }

        if ($data->nuptk !== null && (clone $others)->where('nuptk', $data->nuptk)->exists()) {
            throw EmployeeException::duplicateNuptk();
        }
    }

    private function assertValidInput(EmployeeProfileData $data): void
    {
        if (trim($data->name) === '' || mb_strlen($data->name) > self::NAME_MAX_LENGTH) {
            throw EmployeeException::nameRequired();
        }

        if ($data->birthDate !== null) {
            $this->guard->pastOrToday($data->birthDate, 'birth_date', 'Tanggal lahir');
        }

        if ($data->phone !== null && preg_match(PersonalData::PHONE_PATTERN, $data->phone) !== 1) {
            throw EmployeeException::invalidFormat('phone', 'Nomor HP hanya angka (boleh diawali +), 8–19 digit.');
        }

        if ($data->employeeNumber !== null && preg_match(PersonalData::EMPLOYEE_NUMBER_PATTERN, $data->employeeNumber) !== 1) {
            throw EmployeeException::invalidFormat('employee_number', 'Nomor induk hanya huruf, angka, titik, garis miring, dan tanda "-", maksimal 30 karakter.');
        }

        if ($data->nuptk !== null && preg_match(PersonalData::NUPTK_PATTERN, $data->nuptk) !== 1) {
            throw EmployeeException::invalidFormat('nuptk', 'NUPTK harus 16 angka.');
        }

        if ($data->nik !== null && ! PersonNik::isValid($data->nik)) {
            throw EmployeeException::nikInvalid();
        }
    }
}
