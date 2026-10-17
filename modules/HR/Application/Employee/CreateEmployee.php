<?php

namespace Modules\HR\Application\Employee;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Application\Membership\GrantMembership;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Person\Person;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Domain\Catalog\CatalogItem;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Employee\Employee;
use Modules\HR\Domain\Employee\EmploymentPeriod;
use Modules\HR\Domain\Employee\Exceptions\DuplicatePersonException;
use Modules\HR\Domain\Employee\Exceptions\EmployeeException;
use Modules\HR\Domain\Employee\Placement;

/**
 * Menambah pegawai (PRD-001 §4.4 invarian 4, §6):
 *
 *   [Person + Membership]  (orang baru)  atau  anggota yayasan yang sudah ada
 *   → Pegawai → periode kepegawaian pertama → penempatan pertama (utama)
 *
 * Semuanya dalam SATU transaksi: bila satu langkah gagal (mis. lembaga nonaktif),
 * tidak ada yang tersimpan, termasuk Person dan Membership yang baru ([LAMA] HR-017 INV-HR-012).
 *
 * Tidak membuat akun login (User) dan tidak memberi role apa pun (PRD-001 §4.1, OD-HR-03).
 */
final class CreateEmployee
{
    private const NAME_MAX_LENGTH = 200;

    public function __construct(
        private readonly GrantMembership $grantMembership,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws EmployeeException
     * @throws DuplicatePersonException
     */
    public function handle(NewEmployeeData $data): Employee
    {
        $this->assertValidInput($data);

        try {
            return DB::transaction(fn (): Employee => $this->create($data));
        } catch (UniqueConstraintViolationException $exception) {
            // Jaga-jaga bila dua admin menyimpan data yang sama pada saat bersamaan.
            throw $this->fromUniqueViolation($exception, $data);
        }
    }

    private function create(NewEmployeeData $data): Employee
    {
        [$membership, $person, $personalFields] = $data->membershipId === null
            ? $this->createPerson($data)
            : $this->useExistingMember($data, $data->membershipId);

        // Diperiksa SETELAH Person/Membership dibuat: bila gagal, transaksi membatalkan semuanya.
        $type = $this->activeCatalogItem(CatalogKind::EmploymentType, $data->tenantId, $data->employmentTypeId, 'employment_type_id', 'Jenis pegawai');
        $classification = $data->classificationId === null
            ? null
            : $this->activeCatalogItem(CatalogKind::Classification, $data->tenantId, $data->classificationId, 'classification_id', 'Status kepegawaian');
        $position = $this->activeCatalogItem(CatalogKind::Position, $data->tenantId, $data->positionId, 'position_id', 'Jabatan');
        $organization = $this->activeOrganization($data->tenantId, $data->organizationId);

        $this->assertNumbersAvailable($data);

        $employee = new Employee;
        $employee->fill([
            'tenant_id' => $data->tenantId,
            'membership_id' => $membership->id,
            'employee_number' => $data->employeeNumber,
            'nuptk' => $data->nuptk,
            'education_level' => $data->educationLevel,
            'education_major' => $data->educationMajor,
        ]);
        $employee->save();

        EmploymentPeriod::query()->create([
            'tenant_id' => $data->tenantId,
            'employee_id' => $employee->id,
            'employment_type_id' => $type->id,
            'classification_id' => $classification?->id,
            'start_date' => $data->startDate,
        ]);

        Placement::query()->create([
            'tenant_id' => $data->tenantId,
            'employee_id' => $employee->id,
            'organization_id' => $organization->id,
            'position_id' => $position->id,
            'is_primary' => true,
            'start_date' => $data->startDate,
        ]);

        // Data pribadi hanya dicatat NAMA field-nya, bukan nilainya (PRD-001 §7, [LAMA] HR-014).
        $this->audit->record(
            HrAuditAction::EMPLOYEE_CREATED,
            HrAuditAction::SUBJECT_EMPLOYEE,
            $employee->id,
            [
                'nama' => $person->name,
                'orang_baru' => $data->membershipId === null,
                'employee_number' => $data->employeeNumber,
                'jenis_pegawai' => $type->code,
                'status_kepegawaian' => $classification?->code,
                'start_date' => $data->startDate,
                'lembaga' => $organization->code,
                'jabatan' => $position->code,
                'data_pribadi_diisi' => $personalFields,
            ],
            organizationId: $organization->id,
            tenantId: $data->tenantId,
        );

        return $employee;
    }

    // ── Orang ─────────────────────────────────────────────────────────────────

    /**
     * Orang baru: cek kemungkinan orang ganda, lalu buat Person + Membership.
     *
     * @return array{0: Membership, 1: Person, 2: list<string>}
     */
    private function createPerson(NewEmployeeData $data): array
    {
        $name = $data->name ?? throw EmployeeException::nameRequired();

        if ($data->gender === null) {
            throw EmployeeException::genderRequired();
        }

        if (! $data->confirmDuplicate) {
            [$matches, $sameBirthDate] = $this->similarMembers($data->tenantId, $name, $data->birthDate);

            if ($matches !== []) {
                throw new DuplicatePersonException($matches, $sameBirthDate);
            }
        }

        $personal = $this->personalValues($data);

        $person = Person::query()->create(['name' => $name, 'gender' => $data->gender] + $personal);
        $membership = $this->grantMembership->handle($person->id, $data->tenantId);

        return [$membership, $person, array_keys($personal)];
    }

    /**
     * Anggota yang sudah ada: wajib aktif dan belum punya profil pegawai. Data pribadi yang
     * diisi melengkapi/memperbarui Person-nya; yang dikosongkan tidak diubah.
     *
     * @return array{0: Membership, 1: Person, 2: list<string>}
     */
    private function useExistingMember(NewEmployeeData $data, string $membershipId): array
    {
        $membership = Str::isUuid($membershipId)
            ? Membership::query()->where('tenant_id', $data->tenantId)->whereKey($membershipId)->lockForUpdate()->first()
            : null;

        if (! $membership instanceof Membership) {
            throw EmployeeException::notFound('membership_id', 'Anggota yayasan');
        }

        if (! $membership->isActive()) {
            throw EmployeeException::membershipInactive();
        }

        if (Employee::query()->withoutGlobalScope('tenant')->where('membership_id', $membership->id)->exists()) {
            throw EmployeeException::alreadyEmployee();
        }

        $person = Person::query()->whereKey($membership->person_id)->lockForUpdate()->firstOrFail();

        if ($data->gender === null && $person->gender === null) {
            throw EmployeeException::genderRequired();
        }

        $personal = $this->personalValues($data);
        $updates = $personal + ($data->gender === null ? [] : ['gender' => $data->gender]);

        if ($updates !== []) {
            $person->fill($updates);
            $person->save();
        }

        return [$membership, $person, array_keys($personal)];
    }

    /**
     * Data pribadi yang diisi saja.
     *
     * @return array<string, string>
     */
    private function personalValues(NewEmployeeData $data): array
    {
        $candidates = [
            'birth_place' => $data->birthPlace,
            'birth_date' => $data->birthDate,
            'phone' => $data->phone,
            'address' => $data->address,
        ];

        $values = [];

        foreach ($candidates as $field => $value) {
            if ($value !== null) {
                $values[$field] = $value;
            }
        }

        return $values;
    }

    /**
     * Anggota yayasan ini dengan NAMA yang sama (tanpa beda huruf besar/kecil & spasi ganda), beserta
     * tanggal lahirnya agar admin bisa menilai. Hanya yayasan yang sama, agar data yayasan lain tidak
     * bocor. [ASUMSI, disetujui owner 2026-10-17] Disamakan dengan impor (PRD-001 §6.2): dulu hanya
     * nama + tanggal lahir, sehingga tanggal yang salah ketik/kosong lolos tanpa peringatan.
     *
     * @return array{0: list<string>, 1: bool} keterangan orang yang mirip, dan apakah ada yang tanggal lahirnya juga sama
     */
    private function similarMembers(string $tenantId, string $name, ?string $birthDate): array
    {
        $normalized = mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($name)));

        $rows = DB::table('persons as p')
            ->join('memberships as m', 'm.person_id', '=', 'p.id')
            ->leftJoin('hr_employees as e', 'e.membership_id', '=', 'm.id')
            ->where('m.tenant_id', $tenantId)
            ->whereRaw("lower(regexp_replace(trim(p.name), '\\s+', ' ', 'g')) = ?", [$normalized])
            ->orderBy('p.name')
            ->limit(5)
            ->get(['p.name', 'p.birth_date', 'e.id as employee_id']);

        $matches = [];
        $sameBirthDate = false;

        foreach ($rows as $row) {
            $rowBirthDate = $row->birth_date === null ? null : substr((string) $row->birth_date, 0, 10);
            $sameBirthDate = $sameBirthDate || ($birthDate !== null && $rowBirthDate === $birthDate);

            $matches[] = (string) $row->name.' ('.($row->employee_id === null ? 'anggota' : 'pegawai')
                .', '.($rowBirthDate === null ? 'tanpa tanggal lahir' : 'lahir '.$rowBirthDate).')';
        }

        return [$matches, $sameBirthDate];
    }

    // ── Rujukan ───────────────────────────────────────────────────────────────

    private function activeCatalogItem(CatalogKind $kind, string $tenantId, string $id, string $field, string $label): CatalogItem
    {
        $item = Str::isUuid($id)
            ? $kind->query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($id)->first()
            : null;

        if (! $item instanceof CatalogItem) {
            throw EmployeeException::notFound($field, $label);
        }

        if (! $item->is_active) {
            throw EmployeeException::inactive($field, $label);
        }

        return $item;
    }

    private function activeOrganization(string $tenantId, string $id): Organization
    {
        $organization = Str::isUuid($id)
            ? Organization::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($id)->first()
            : null;

        if (! $organization instanceof Organization) {
            throw EmployeeException::notFound('organization_id', 'Lembaga');
        }

        if (! $organization->isActive()) {
            throw EmployeeException::organizationInactive();
        }

        return $organization;
    }

    private function assertNumbersAvailable(NewEmployeeData $data): void
    {
        $employees = Employee::query()->withoutGlobalScope('tenant')->where('tenant_id', $data->tenantId);

        if ($data->employeeNumber !== null && (clone $employees)->where('employee_number', $data->employeeNumber)->exists()) {
            throw EmployeeException::duplicateEmployeeNumber($data->employeeNumber);
        }

        if ($data->nuptk !== null && (clone $employees)->where('nuptk', $data->nuptk)->exists()) {
            throw EmployeeException::duplicateNuptk();
        }
    }

    // ── Validasi isian ────────────────────────────────────────────────────────

    /**
     * Penjaga di tingkat service (form sudah memvalidasi lebih dulu); database tetap lapisan terakhir.
     */
    private function assertValidInput(NewEmployeeData $data): void
    {
        if ($data->membershipId === null && ($data->name === null || mb_strlen($data->name) > self::NAME_MAX_LENGTH)) {
            throw EmployeeException::nameRequired();
        }

        $today = BusinessClock::todayString();

        if (! $this->isDate($data->startDate)) {
            throw EmployeeException::invalidFormat('start_date', 'Tanggal mulai tidak valid.');
        }

        if ($data->startDate > $today) {
            throw EmployeeException::futureDate('start_date', 'Tanggal mulai');
        }

        if ($data->birthDate !== null && ! $this->isDate($data->birthDate)) {
            throw EmployeeException::invalidFormat('birth_date', 'Tanggal lahir tidak valid.');
        }

        if ($data->birthDate !== null && $data->birthDate > $today) {
            throw EmployeeException::futureDate('birth_date', 'Tanggal lahir');
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
    }

    /**
     * Tanggal kalender yang sah dengan format YYYY-MM-DD (perbandingan string aman untuk format ini).
     */
    private function isDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    private function fromUniqueViolation(UniqueConstraintViolationException $exception, NewEmployeeData $data): EmployeeException
    {
        $message = $exception->getMessage();

        return match (true) {
            str_contains($message, 'employee_number') && $data->employeeNumber !== null => EmployeeException::duplicateEmployeeNumber($data->employeeNumber),
            str_contains($message, 'nuptk') => EmployeeException::duplicateNuptk(),
            str_contains($message, 'membership_id') => EmployeeException::alreadyEmployee(),
            default => EmployeeException::conflict(),
        };
    }
}
