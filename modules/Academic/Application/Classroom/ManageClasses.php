<?php

namespace Modules\Academic\Application\Classroom;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Application\Calendar\AcademicCalendarResolver;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Academic\Domain\Classroom\Exceptions\ClassException;
use Modules\Academic\Domain\Classroom\SchoolClass;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Contracts\ActiveEmployees;

/**
 * Kelas / rombel (PRD-002 §4.2, §6; AC-1d-a): tambah, ubah (nama, tingkat, wali kelas),
 * nonaktifkan/aktifkan kembali, hapus kelas yang belum pernah punya anggota.
 *
 * Aturan [OWNER, 2026-10-20]:
 * - Tahun ajaran wajib dari kalender yang berlaku untuk lembaga itu (§4.4).
 * - Kelas tahun ajaran yang sudah lewat hanya bisa dilihat (tidak bisa diubah).
 * - Tingkat 1–12 untuk semua lembaga (belum dibatasi per jenjang).
 * - Wali kelas: pegawai aktif dengan penempatan berjalan di lembaga itu (kontrak HR ActiveEmployees);
 *   boleh memegang lebih dari satu kelas [ASUMSI]. Wali kelas hanya melihat kelasnya di menu Kelas saya (§7, AC-3d).
 *
 * Hak akses (academic.classes.manage di lembaga kelas) dicek pemanggil lewat Gate.
 */
final class ManageClasses
{
    public const NAME_MAX_LENGTH = 50;

    public function __construct(
        private readonly AcademicCalendarResolver $calendars,
        private readonly ActiveEmployees $employees,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Tahun ajaran yang sudah lewat: bukan tahun berjalan dan sudah selesai.
     */
    public static function isPastYear(AcademicYear $year): bool
    {
        return ! $year->is_current && $year->end_date->toDateString() < BusinessClock::todayString();
    }

    /**
     * @throws ClassException
     */
    public function create(string $tenantId, string $organizationId, string $academicYearId, ClassData $data): SchoolClass
    {
        $this->assertValidInput($data);

        return $this->transaction($data, function () use ($tenantId, $organizationId, $academicYearId, $data): SchoolClass {
            $organization = $this->activeLembaga($tenantId, $organizationId);
            $year = $this->yearFor($tenantId, $organization, $academicYearId);
            $this->assertNameFree($organization->id, $year->id, $data->name, null);

            if ($data->homeroomEmployeeId !== null && ! $this->employees->isActiveIn($tenantId, $data->homeroomEmployeeId, $organization->id)) {
                throw ClassException::homeroomNotActive();
            }

            $class = new SchoolClass;
            $class->fill([
                'tenant_id' => $tenantId,
                'organization_id' => $organization->id,
                'academic_year_id' => $year->id,
                'grade' => $data->grade,
                'name' => $data->name,
                'homeroom_employee_id' => $data->homeroomEmployeeId,
            ]);
            $class->save();

            $this->audit->record(
                AcademicAuditAction::CLASS_CREATED,
                AcademicAuditAction::SUBJECT_CLASS,
                $class->id,
                [
                    'nama' => $class->name,
                    'lembaga' => $organization->code,
                    'tahun_ajaran' => $year->name,
                    'tingkat' => $class->grade,
                    'wali_kelas_diisi' => $class->homeroom_employee_id !== null,
                ],
                organizationId: $organization->id,
                tenantId: $tenantId,
            );

            return $class;
        });
    }

    /**
     * @return list<string> field yang berubah
     *
     * @throws ClassException
     */
    public function update(string $tenantId, string $classId, ClassData $data): array
    {
        $this->assertValidInput($data);

        return $this->transaction($data, function () use ($tenantId, $classId, $data): array {
            $class = $this->lockEditable($tenantId, $classId);
            $this->assertNameFree($class->organization_id, $class->academic_year_id, $data->name, $class->id);

            // Wali kelas lama yang sudah tidak aktif boleh tetap tercatat sampai diganti (§7).
            $homeroomChanged = $data->homeroomEmployeeId !== $class->homeroom_employee_id;

            if ($homeroomChanged && $data->homeroomEmployeeId !== null && ! $this->employees->isActiveIn($tenantId, $data->homeroomEmployeeId, $class->organization_id)) {
                throw ClassException::homeroomNotActive();
            }

            $class->fill([
                'grade' => $data->grade,
                'name' => $data->name,
                'homeroom_employee_id' => $data->homeroomEmployeeId,
            ]);
            $changed = array_keys($class->getDirty());

            if ($changed === []) {
                return [];
            }

            $class->save();

            $this->audit->record(
                AcademicAuditAction::CLASS_UPDATED,
                AcademicAuditAction::SUBJECT_CLASS,
                $class->id,
                ['nama' => $class->name, 'field_diubah' => $changed, 'wali_kelas_diganti' => $homeroomChanged],
                organizationId: $class->organization_id,
                tenantId: $tenantId,
            );

            return $changed;
        });
    }

    /**
     * Nonaktif = tidak bisa diisi siswa baru; anggota & riwayat tetap (§4.2).
     *
     * @throws ClassException
     */
    public function setActive(string $tenantId, string $classId, bool $active): SchoolClass
    {
        return DB::transaction(function () use ($tenantId, $classId, $active): SchoolClass {
            $class = $this->lockEditable($tenantId, $classId);

            if ($class->is_active === $active) {
                return $class;
            }

            $class->is_active = $active;
            $class->save();

            $this->audit->record(
                $active ? AcademicAuditAction::CLASS_REACTIVATED : AcademicAuditAction::CLASS_DEACTIVATED,
                AcademicAuditAction::SUBJECT_CLASS,
                $class->id,
                ['nama' => $class->name],
                organizationId: $class->organization_id,
                tenantId: $tenantId,
            );

            return $class;
        });
    }

    /**
     * Hanya kelas yang belum pernah punya anggota; selain itu nonaktifkan (§4.2, invarian 3).
     *
     * @throws ClassException
     */
    public function delete(string $tenantId, string $classId): string
    {
        return DB::transaction(function () use ($tenantId, $classId): string {
            $class = $this->lockEditable($tenantId, $classId);

            if (DB::table('ac_class_members')->where('class_id', $class->id)->exists()) {
                throw ClassException::make('form', 'Kelas ini sudah pernah punya anggota, jadi tidak bisa dihapus. Nonaktifkan saja.');
            }

            $name = $class->name;
            $class->delete();

            $this->audit->record(
                AcademicAuditAction::CLASS_DELETED,
                AcademicAuditAction::SUBJECT_CLASS,
                $class->id,
                ['nama' => $name],
                organizationId: $class->organization_id,
                tenantId: $tenantId,
            );

            return $name;
        });
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function activeLembaga(string $tenantId, string $organizationId): Organization
    {
        $organization = Str::isUuid($organizationId)
            ? Organization::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($organizationId)->first()
            : null;

        if (! $organization instanceof Organization) {
            throw ClassException::make('organization_id', 'Lembaga tidak ditemukan di yayasan ini.');
        }

        if ($organization->type !== OrganizationType::Lembaga) {
            throw ClassException::make('organization_id', 'Kelas hanya bisa dibuat di lembaga (bukan unit atau biro).');
        }

        if (! $organization->isActive()) {
            throw ClassException::make('organization_id', 'Lembaga sudah nonaktif.');
        }

        return $organization;
    }

    /**
     * Tahun ajaran wajib dari kalender yang berlaku untuk lembaga ini dan belum lewat.
     */
    private function yearFor(string $tenantId, Organization $organization, string $academicYearId): AcademicYear
    {
        $year = Str::isUuid($academicYearId)
            ? AcademicYear::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($academicYearId)->first()
            : null;
        $calendar = $this->calendars->calendarFor($tenantId, $organization->id);

        if (! $year instanceof AcademicYear || $calendar === null || $year->calendar_id !== $calendar->id) {
            throw ClassException::make('academic_year_id', "Tahun ajaran ini bukan dari kalender yang dipakai {$organization->name}.");
        }

        if (self::isPastYear($year)) {
            throw ClassException::pastYear();
        }

        return $year;
    }

    private function lockEditable(string $tenantId, string $classId): SchoolClass
    {
        $class = Str::isUuid($classId)
            ? SchoolClass::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($classId)->lockForUpdate()->first()
            : null;

        if (! $class instanceof SchoolClass) {
            throw ClassException::make('form', 'Kelas tidak ditemukan di yayasan ini.');
        }

        $year = AcademicYear::query()->withoutGlobalScope('tenant')->whereKey($class->academic_year_id)->firstOrFail();

        if (self::isPastYear($year)) {
            throw ClassException::pastYear();
        }

        return $class;
    }

    private function assertNameFree(string $organizationId, string $academicYearId, string $name, ?string $exceptClassId): void
    {
        $query = SchoolClass::query()
            ->withoutGlobalScope('tenant')
            ->where('organization_id', $organizationId)
            ->where('academic_year_id', $academicYearId)
            ->whereRaw('lower(name) = lower(?)', [$name]);

        if ($exceptClassId !== null) {
            $query->whereKeyNot($exceptClassId);
        }

        if ($query->exists()) {
            throw ClassException::duplicateName($name);
        }
    }

    private function assertValidInput(ClassData $data): void
    {
        if ($data->grade < 1 || $data->grade > 12) {
            throw ClassException::make('grade', 'Tingkat harus angka 1–12.');
        }

        if ($data->name === '' || mb_strlen($data->name) > self::NAME_MAX_LENGTH) {
            throw ClassException::make('name', 'Nama kelas wajib diisi, maksimal '.self::NAME_MAX_LENGTH.' karakter.');
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function transaction(ClassData $data, Closure $callback): mixed
    {
        try {
            return DB::transaction($callback);
        } catch (UniqueConstraintViolationException) {
            // Dua operator menyimpan nama yang sama bersamaan.
            throw ClassException::duplicateName($data->name);
        }
    }
}
