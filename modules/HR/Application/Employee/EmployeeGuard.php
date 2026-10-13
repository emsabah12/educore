<?php

namespace Modules\HR\Application\Employee;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Domain\Catalog\CatalogItem;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Employee\Employee;
use Modules\HR\Domain\Employee\EmployeeStatus;
use Modules\HR\Domain\Employee\EmploymentPeriod;
use Modules\HR\Domain\Employee\Exceptions\EmployeeException;
use Modules\HR\Domain\Employee\Placement;

/**
 * Pemeriksaan bersama untuk aksi pada pegawai yang sudah ada (HR-1d, PRD-001 §4.3–§4.4).
 *
 * Semua method "lock…" WAJIB dipanggil di dalam transaksi: baris pegawai dikunci lebih dulu
 * agar dua admin yang mengubah pegawai yang sama tidak saling menimpa.
 */
final class EmployeeGuard
{
    public function lockEmployee(string $tenantId, string $employeeId): Employee
    {
        $employee = Str::isUuid($employeeId)
            ? Employee::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($employeeId)->lockForUpdate()->first()
            : null;

        if (! $employee instanceof Employee) {
            throw EmployeeException::notFound('form', 'Pegawai');
        }

        return $employee;
    }

    public function assertActive(Employee $employee): void
    {
        if ($employee->status !== EmployeeStatus::Active) {
            throw EmployeeException::rule('form', 'Pegawai ini sudah berhenti. Aktifkan kembali dulu.');
        }
    }

    /**
     * Periode kepegawaian yang sedang berjalan (pegawai aktif selalu punya satu).
     */
    public function openPeriod(Employee $employee): EmploymentPeriod
    {
        $period = EmploymentPeriod::query()
            ->withoutGlobalScope('tenant')
            ->where('employee_id', $employee->id)
            ->whereNull('end_date')
            ->lockForUpdate()
            ->first();

        if (! $period instanceof EmploymentPeriod) {
            throw EmployeeException::rule('form', 'Pegawai ini tidak punya periode kepegawaian yang berjalan.');
        }

        return $period;
    }

    /**
     * Penempatan pegawai ini yang masih berjalan.
     *
     * @return list<Placement>
     */
    public function openPlacements(Employee $employee): array
    {
        return array_values(Placement::query()
            ->withoutGlobalScope('tenant')
            ->where('employee_id', $employee->id)
            ->whereNull('end_date')
            ->orderByDesc('is_primary')
            ->orderBy('start_date')
            ->lockForUpdate()
            ->get()
            ->all());
    }

    /**
     * Penempatan milik pegawai ini yang masih berjalan.
     */
    public function openPlacement(Employee $employee, string $placementId, string $field = 'form'): Placement
    {
        foreach ($this->openPlacements($employee) as $placement) {
            if ($placement->id === $placementId) {
                return $placement;
            }
        }

        throw EmployeeException::rule($field, 'Penempatan tidak ditemukan atau sudah berakhir.');
    }

    public function activeCatalogItem(CatalogKind $kind, string $tenantId, string $id, string $field, string $label): CatalogItem
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

    public function activeOrganization(string $tenantId, string $id): Organization
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

    /**
     * Tanggal YYYY-MM-DD yang sah dan tidak di masa depan (tanggal yayasan). Backdate boleh (HR-1d).
     */
    public function pastOrToday(string $value, string $field, string $label): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw EmployeeException::invalidFormat($field, "{$label} tidak valid.");
        }

        if ($value > BusinessClock::todayString()) {
            throw EmployeeException::futureDate($field, $label);
        }

        return $value;
    }

    public static function dayBefore(string $date): string
    {
        return CarbonImmutable::parse($date)->subDay()->toDateString();
    }

    private const MONTHS = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    /**
     * "2020-07-01" → "1 Juli 2020" untuk pesan kesalahan.
     */
    public static function formatDate(string $date): string
    {
        $parsed = CarbonImmutable::parse($date);

        return $parsed->day.' '.self::MONTHS[$parsed->month].' '.$parsed->year;
    }
}
