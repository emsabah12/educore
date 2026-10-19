<?php

namespace Modules\Academic\Application\Student;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Domain\Student\Exceptions\StudentException;
use Modules\Academic\Domain\Student\Student;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Domain\Person\Person;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Support\BusinessClock;

/**
 * Ubah data diri siswa (PRD-002 §6, AC-1c-b1): nama, jenis kelamin, data pribadi di Person, dan NISN.
 *
 * Siapa yang boleh (OD-AC-09) dicek pemanggil lewat StudentManageAccess. Data diri milik Person,
 * jadi bila orang yang sama juga pegawai atau punya akun login, namanya ikut berubah di sana
 * (sama dengan ubah profil pegawai, PRD-001 §6).
 *
 * Audit hanya mencatat NAMA field yang berubah, bukan nilainya (PRD-002 §4.5, §8).
 */
final class UpdateStudentProfile
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return list<string> field yang berubah (kosong = tidak ada perubahan)
     *
     * @throws StudentException
     */
    public function handle(string $tenantId, string $studentId, StudentProfileData $data): array
    {
        $this->assertValidInput($data);

        try {
            return DB::transaction(function () use ($tenantId, $studentId, $data): array {
                $student = Str::isUuid($studentId)
                    ? Student::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($studentId)->lockForUpdate()->first()
                    : null;

                if (! $student instanceof Student) {
                    throw StudentException::make('form', 'Siswa tidak ditemukan di yayasan ini.');
                }

                if ($data->nisn !== null) {
                    $taken = Student::query()
                        ->withoutGlobalScope('tenant')
                        ->where('tenant_id', $tenantId)
                        ->where('nisn', $data->nisn)
                        ->whereKeyNot($student->id)
                        ->exists();

                    if ($taken) {
                        throw StudentException::duplicateNisn();
                    }
                }

                $membership = Membership::query()->withoutGlobalScope('tenant')->whereKey($student->membership_id)->firstOrFail();
                $person = Person::query()->whereKey($membership->person_id)->lockForUpdate()->firstOrFail();

                $person->fill([
                    'name' => $data->name,
                    'gender' => $data->gender,
                    'birth_place' => $data->birthPlace,
                    'birth_date' => $data->birthDate,
                    'phone' => $data->phone,
                    'address' => $data->address,
                ]);
                $student->nisn = $data->nisn;

                $changed = array_values(array_unique(array_merge(
                    array_keys($person->getDirty()),
                    array_keys($student->getDirty()),
                )));

                if ($changed === []) {
                    return [];
                }

                $person->save();
                $student->save();

                $this->audit->record(
                    AcademicAuditAction::STUDENT_UPDATED,
                    AcademicAuditAction::SUBJECT_STUDENT,
                    $student->id,
                    ['nama' => $person->name, 'field_diubah' => $changed],
                    tenantId: $tenantId,
                );

                return $changed;
            });
        } catch (UniqueConstraintViolationException) {
            // Dua operator mengisi NISN yang sama pada saat bersamaan.
            throw StudentException::duplicateNisn();
        }
    }

    /**
     * Penjaga di tingkat service (form sudah memvalidasi lebih dulu); database tetap lapisan terakhir.
     */
    private function assertValidInput(StudentProfileData $data): void
    {
        if (trim($data->name) === '' || mb_strlen($data->name) > StudentInput::NAME_MAX_LENGTH) {
            throw StudentException::nameRequired();
        }

        if ($data->birthDate !== null) {
            if (! StudentInput::isDate($data->birthDate)) {
                throw StudentException::make('birth_date', 'Tanggal lahir tidak valid.');
            }

            if ($data->birthDate > BusinessClock::todayString()) {
                throw StudentException::futureDate('birth_date', 'Tanggal lahir');
            }
        }

        if ($data->phone !== null && preg_match(StudentInput::PHONE_PATTERN, $data->phone) !== 1) {
            throw StudentException::make('phone', 'Nomor HP hanya angka (boleh diawali +), 8–19 digit.');
        }

        if ($data->nisn !== null && preg_match(StudentInput::NISN_PATTERN, $data->nisn) !== 1) {
            throw StudentException::make('nisn', 'NISN harus 10 angka.');
        }
    }
}
