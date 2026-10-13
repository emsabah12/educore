<?php

namespace Modules\HR\Application\Employee;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Domain\Organization\Organization;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Domain\Catalog\CatalogItem;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Employee\Employee;
use Modules\HR\Domain\Employee\Exceptions\EmployeeException;
use Modules\HR\Domain\Employee\Placement;

/**
 * Penempatan pegawai yang masih aktif (PRD-001 §4.3): tambah, akhiri, mutasi, jadikan utama.
 *
 * Aturan yang dijaga:
 * - hanya untuk pegawai AKTIF; tanggal tidak di masa depan dan tidak sebelum awal periode berjalan;
 * - jabatan yang sama di lembaga yang sama tidak boleh dobel selama berjalan;
 * - maksimal satu penempatan utama yang berjalan (juga dijaga partial unique index).
 *
 * Jabatan BUKAN hak akses: tidak ada role/penugasan Core yang berubah di sini.
 */
final class ManagePlacements
{
    public function __construct(
        private readonly EmployeeGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Tambah penempatan baru (mis. guru juga mengajar di MDA). Menjadi utama bila diminta,
     * atau bila pegawai belum punya penempatan utama yang berjalan.
     *
     * @throws EmployeeException
     */
    public function add(string $tenantId, string $employeeId, string $organizationId, string $positionId, string $startDate, bool $makePrimary): Placement
    {
        $startDate = $this->guard->pastOrToday($startDate, 'start_date', 'Tanggal mulai');

        return DB::transaction(function () use ($tenantId, $employeeId, $organizationId, $positionId, $startDate, $makePrimary): Placement {
            $employee = $this->guard->lockEmployee($tenantId, $employeeId);
            $this->guard->assertActive($employee);
            $this->assertWithinCurrentPeriod($employee, $startDate, 'start_date');

            $organization = $this->guard->activeOrganization($tenantId, $organizationId);
            $position = $this->guard->activeCatalogItem(CatalogKind::Position, $tenantId, $positionId, 'position_id', 'Jabatan');
            $open = $this->guard->openPlacements($employee);

            $this->assertNotDuplicate($open, $organization, $position);

            $hasPrimary = array_filter($open, fn (Placement $placement): bool => $placement->is_primary) !== [];
            $primary = $makePrimary || ! $hasPrimary;

            if ($primary) {
                $this->clearPrimary($open);
            }

            $placement = Placement::query()->create([
                'tenant_id' => $tenantId,
                'employee_id' => $employee->id,
                'organization_id' => $organization->id,
                'position_id' => $position->id,
                'is_primary' => $primary,
                'start_date' => $startDate,
            ]);

            $this->audit->record(
                HrAuditAction::PLACEMENT_ADDED,
                HrAuditAction::SUBJECT_EMPLOYEE,
                $employee->id,
                ['lembaga' => $organization->code, 'jabatan' => $position->code, 'start_date' => $startDate, 'utama' => $primary],
                organizationId: $organization->id,
                tenantId: $tenantId,
            );

            return $placement;
        });
    }

    /**
     * Akhiri satu penempatan. Bila yang diakhiri penempatan utama dan masih ada penempatan lain
     * yang berjalan, $newPrimaryId wajib menunjuk penggantinya.
     *
     * @throws EmployeeException
     */
    public function end(string $tenantId, string $employeeId, string $placementId, string $endDate, ?string $newPrimaryId): Placement
    {
        $endDate = $this->guard->pastOrToday($endDate, 'end_date', 'Tanggal berakhir');

        return DB::transaction(function () use ($tenantId, $employeeId, $placementId, $endDate, $newPrimaryId): Placement {
            $employee = $this->guard->lockEmployee($tenantId, $employeeId);
            $this->guard->assertActive($employee);

            $placement = $this->guard->openPlacement($employee, $placementId);
            $this->assertNotBeforeStart($placement, $endDate, 'end_date');

            $others = array_values(array_filter(
                $this->guard->openPlacements($employee),
                fn (Placement $other): bool => $other->id !== $placement->id,
            ));

            $replacement = null;

            if ($placement->is_primary && $others !== []) {
                $replacement = $this->findAmong($others, $newPrimaryId);

                if ($replacement === null) {
                    throw EmployeeException::rule('new_primary_id', 'Pilih penempatan pengganti sebagai penempatan utama.');
                }
            }

            $placement->end_date = CarbonImmutable::parse($endDate);
            $placement->save();

            if ($replacement !== null) {
                $replacement->is_primary = true;
                $replacement->save();
            }

            $this->audit->record(
                HrAuditAction::PLACEMENT_ENDED,
                HrAuditAction::SUBJECT_EMPLOYEE,
                $employee->id,
                [
                    'lembaga' => $this->organizationCode($placement->organization_id),
                    'jabatan' => $this->positionCode($placement->position_id),
                    'end_date' => $endDate,
                    'utama_baru' => $replacement === null ? null : $this->organizationCode($replacement->organization_id),
                ],
                organizationId: $placement->organization_id,
                tenantId: $tenantId,
            );

            return $placement;
        });
    }

    /**
     * Mutasi: penempatan lama ditutup sehari sebelum $effectiveDate, penempatan baru dibuka
     * pada $effectiveDate dalam satu transaksi. Status "utama" ikut pindah.
     *
     * @throws EmployeeException
     */
    public function transfer(string $tenantId, string $employeeId, string $placementId, string $organizationId, string $positionId, string $effectiveDate): Placement
    {
        $effectiveDate = $this->guard->pastOrToday($effectiveDate, 'effective_date', 'Tanggal mutasi');

        return DB::transaction(function () use ($tenantId, $employeeId, $placementId, $organizationId, $positionId, $effectiveDate): Placement {
            $employee = $this->guard->lockEmployee($tenantId, $employeeId);
            $this->guard->assertActive($employee);

            $old = $this->guard->openPlacement($employee, $placementId);
            $oldStart = $old->start_date->toDateString();

            if ($effectiveDate <= $oldStart) {
                throw EmployeeException::rule('effective_date', 'Tanggal mutasi harus sesudah awal penempatan lama ('.EmployeeGuard::formatDate($oldStart).').');
            }

            $organization = $this->guard->activeOrganization($tenantId, $organizationId);
            $position = $this->guard->activeCatalogItem(CatalogKind::Position, $tenantId, $positionId, 'position_id', 'Jabatan');

            $others = array_values(array_filter(
                $this->guard->openPlacements($employee),
                fn (Placement $other): bool => $other->id !== $old->id,
            ));
            $this->assertNotDuplicate($others, $organization, $position);

            if ($old->organization_id === $organization->id && $old->position_id === $position->id) {
                throw EmployeeException::rule('organization_id', 'Lembaga dan jabatan tujuan sama dengan penempatan sekarang.');
            }

            // Tutup dulu yang lama agar indeks "satu utama yang berjalan" tidak bentrok.
            $old->end_date = CarbonImmutable::parse(EmployeeGuard::dayBefore($effectiveDate));
            $old->save();

            $new = Placement::query()->create([
                'tenant_id' => $tenantId,
                'employee_id' => $employee->id,
                'organization_id' => $organization->id,
                'position_id' => $position->id,
                'is_primary' => $old->is_primary,
                'start_date' => $effectiveDate,
            ]);

            $this->audit->record(
                HrAuditAction::PLACEMENT_TRANSFERRED,
                HrAuditAction::SUBJECT_EMPLOYEE,
                $employee->id,
                [
                    'lembaga' => [$this->organizationCode($old->organization_id), $organization->code],
                    'jabatan' => [$this->positionCode($old->position_id), $position->code],
                    'berlaku' => $effectiveDate,
                ],
                organizationId: $organization->id,
                tenantId: $tenantId,
            );

            return $new;
        });
    }

    /**
     * @throws EmployeeException
     */
    public function makePrimary(string $tenantId, string $employeeId, string $placementId): Placement
    {
        return DB::transaction(function () use ($tenantId, $employeeId, $placementId): Placement {
            $employee = $this->guard->lockEmployee($tenantId, $employeeId);
            $this->guard->assertActive($employee);

            $placement = $this->guard->openPlacement($employee, $placementId);

            if ($placement->is_primary) {
                return $placement;
            }

            $this->clearPrimary($this->guard->openPlacements($employee));

            $placement->is_primary = true;
            $placement->save();

            $this->audit->record(
                HrAuditAction::PLACEMENT_PRIMARY_CHANGED,
                HrAuditAction::SUBJECT_EMPLOYEE,
                $employee->id,
                ['lembaga' => $this->organizationCode($placement->organization_id), 'jabatan' => $this->positionCode($placement->position_id)],
                organizationId: $placement->organization_id,
                tenantId: $tenantId,
            );

            return $placement;
        });
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function assertWithinCurrentPeriod(Employee $employee, string $date, string $field): void
    {
        $periodStart = $this->guard->openPeriod($employee)->start_date->toDateString();

        if ($date < $periodStart) {
            throw EmployeeException::rule($field, 'Tanggal tidak boleh sebelum awal periode kepegawaian ('.EmployeeGuard::formatDate($periodStart).').');
        }
    }

    private function assertNotBeforeStart(Placement $placement, string $date, string $field): void
    {
        $start = $placement->start_date->toDateString();

        if ($date < $start) {
            throw EmployeeException::rule($field, 'Tanggal tidak boleh sebelum awal penempatan ('.EmployeeGuard::formatDate($start).').');
        }
    }

    /**
     * @param  list<Placement>  $open
     */
    private function assertNotDuplicate(array $open, Organization $organization, CatalogItem $position): void
    {
        foreach ($open as $placement) {
            if ($placement->organization_id === $organization->id && $placement->position_id === $position->id) {
                throw EmployeeException::rule('position_id', "Pegawai ini sudah menjabat {$position->name} di {$organization->name}.");
            }
        }
    }

    /**
     * @param  list<Placement>  $open
     */
    private function clearPrimary(array $open): void
    {
        foreach ($open as $placement) {
            if ($placement->is_primary) {
                $placement->is_primary = false;
                $placement->save();
            }
        }
    }

    /**
     * @param  list<Placement>  $candidates
     */
    private function findAmong(array $candidates, ?string $placementId): ?Placement
    {
        foreach ($candidates as $candidate) {
            if ($candidate->id === $placementId) {
                return $candidate;
            }
        }

        return null;
    }

    private function organizationCode(string $organizationId): string
    {
        return (string) Organization::query()->withoutGlobalScope('tenant')->whereKey($organizationId)->value('code');
    }

    private function positionCode(string $positionId): string
    {
        return (string) CatalogKind::Position->query()->withoutGlobalScope('tenant')->whereKey($positionId)->value('code');
    }
}
