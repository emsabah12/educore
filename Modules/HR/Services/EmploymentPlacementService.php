<?php

declare(strict_types=1);

namespace Modules\HR\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Organization\Models\OrganizationalAssignment;
use Modules\HR\Exceptions\EmploymentLifecycleException;
use Modules\HR\Models\Employment;
use Modules\HR\Models\EmploymentPlacement;
use Modules\HR\Services\Concerns\LocksEmploymentRecords;

/**
 * Implementasi algoritma transaksi "Create Placement" dari HR-002 §9.2.
 *
 * Kelas ini adalah TITIK PERTEMUAN antara domain HR dan domain Core
 * Organization — dia membaca (tapi tidak pernah menulis) tabel
 * `organizational_assignments` milik Core untuk menegakkan dua invariant
 * lintas-domain:
 *
 * - INV-HR-004: Placement hanya boleh merujuk Core Assignment yang
 *   Membership-nya SAMA PERSIS dengan Membership milik Employee pemilik
 *   Employment ini. Tanpa pengecekan ini, secara teori Employment A bisa
 *   "menempel" ke assignment organisasi milik orang lain.
 * - INV-HR-005: Core Assignment yang dirujuk harus berstatus ACTIVE.
 *   HR tidak boleh membuat riwayat penempatan baru ke assignment yang
 *   sudah tidak berlaku.
 */
final readonly class EmploymentPlacementService
{
    use LocksEmploymentRecords;

    /**
     * HR-002 §9.2 — Create Placement transaction algorithm (10 langkah).
     *
     * @param array{
     *     organizational_assignment_id: string,
     *     effective_from: string,
     *     is_primary?: bool,
     * } $data
     */
    public function createPlacement(
        string $tenantId,
        string $employmentId,
        array $data,
    ): EmploymentPlacement {
        return DB::transaction(function () use (
            $tenantId,
            $employmentId,
            $data,
        ): EmploymentPlacement {
            // Langkah 1: lock Employment.
            $employment = $this->lockEmploymentForTenant(
                $employmentId,
                $tenantId,
            );

            // Langkah 2: Employment wajib ACTIVE.
            if ($employment->status !== Employment::STATUS_ACTIVE) {
                throw new EmploymentLifecycleException(
                    sprintf(
                        'Employment [%s] must be ACTIVE to create a Placement (currently [%s]).',
                        $employmentId,
                        $employment->status,
                    ),
                );
            }

            $effectiveFrom = Carbon::parse($data['effective_from']);

            // Langkah 3: effective_from >= employment.start_date.
            if ($effectiveFrom->lt($employment->start_date)) {
                throw new EmploymentLifecycleException(
                    sprintf(
                        'Placement effective_from [%s] cannot be earlier than Employment start_date [%s].',
                        $effectiveFrom->toDateString(),
                        $employment->start_date->toDateString(),
                    ),
                );
            }

            // Langkah 4: Phase 2A — belum mendukung future-dated
            // activation, jadi effective_from tidak boleh melewati
            // tanggal bisnis saat ini.
            if ($effectiveFrom->gt(Carbon::today())) {
                throw new EmploymentLifecycleException(
                    'Placement effective_from cannot be in the future. Scheduled/future placement activation is not supported in Phase 2A.',
                );
            }

            // Langkah 5: resolve Core OrganizationalAssignment di tenant
            // yang sama, sekaligus dikunci supaya tidak berubah status
            // di tengah transaksi ini (misal dinonaktifkan bersamaan).
            /** @var OrganizationalAssignment|null $assignment */
            $assignment = OrganizationalAssignment::query()
                ->withoutGlobalScope('tenant')
                ->where('id', $data['organizational_assignment_id'])
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();

            if ($assignment === null) {
                throw (new ModelNotFoundException())->setModel(
                    OrganizationalAssignment::class,
                    [$data['organizational_assignment_id']],
                );
            }

            // Langkah 6 (INV-HR-005): Assignment wajib ACTIVE.
            if ($assignment->status !== OrganizationalAssignment::STATUS_ACTIVE) {
                throw new EmploymentLifecycleException(
                    sprintf(
                        'OrganizationalAssignment [%s] is not ACTIVE. Open placements may only reference an active Core assignment (INV-HR-005).',
                        $assignment->id,
                    ),
                );
            }

            // Langkah 7 (INV-HR-004): Membership Assignment harus sama
            // dengan Membership Employee pemilik Employment ini.
            $employee = $this->lockEmployeeForTenant(
                $employment->employee_id,
                $tenantId,
            );

            if ($assignment->membership_id !== $employee->membership_id) {
                throw new EmploymentLifecycleException(
                    sprintf(
                        'OrganizationalAssignment [%s] belongs to a different Membership than Employee [%s] (INV-HR-004).',
                        $assignment->id,
                        $employee->id,
                    ),
                );
            }

            // Langkah 8 — cek aplikasi (pre-check) untuk placement
            // terbuka duplikat. Database partial unique index
            // (`uq_employment_placements_open_assignment`) tetap jadi
            // garda terakhir untuk race condition di catch di bawah.
            $hasDuplicateOpenPlacement = EmploymentPlacement::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('employment_id', $employmentId)
                ->where('organizational_assignment_id', $assignment->id)
                ->whereNull('effective_to')
                ->exists();

            if ($hasDuplicateOpenPlacement) {
                throw new EmploymentLifecycleException(
                    sprintf(
                        'Employment [%s] already has an open Placement referencing OrganizationalAssignment [%s].',
                        $employmentId,
                        $assignment->id,
                    ),
                );
            }

            $isPrimary = $data['is_primary'] ?? false;

            // Langkah 9 (INV-HR-009) — cek aplikasi (pre-check) untuk
            // primary placement terbuka ganda.
            if ($isPrimary) {
                $hasOtherOpenPrimary = EmploymentPlacement::query()
                    ->withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenantId)
                    ->where('employment_id', $employmentId)
                    ->where('is_primary', true)
                    ->whereNull('effective_to')
                    ->exists();

                if ($hasOtherOpenPrimary) {
                    throw new EmploymentLifecycleException(
                        sprintf(
                            'Employment [%s] already has an open primary Placement. Only one open primary Placement is allowed at a time (INV-HR-009).',
                            $employmentId,
                        ),
                    );
                }
            }

            // Langkah 10: insert, dengan database partial unique index
            // sebagai jaring pengaman terakhir untuk race condition yang
            // lolos dari pengecekan langkah 8 & 9 di atas.
            try {
                return EmploymentPlacement::create([
                    'employment_id' => $employmentId,
                    'organizational_assignment_id' => $assignment->id,
                    'effective_from' => $effectiveFrom->toDateString(),
                    'is_primary' => $isPrimary,
                ]);
            } catch (QueryException $exception) {
                if (str_contains($exception->getMessage(), 'uq_employment_placements_open_assignment')) {
                    throw new EmploymentLifecycleException(
                        sprintf(
                            'Employment [%s] already has an open Placement referencing this OrganizationalAssignment (concurrent creation detected).',
                            $employmentId,
                        ),
                        previous: $exception,
                    );
                }

                if (str_contains($exception->getMessage(), 'uq_employment_placements_open_primary')) {
                    throw new EmploymentLifecycleException(
                        sprintf(
                            'Employment [%s] already has an open primary Placement (concurrent creation detected, INV-HR-009).',
                            $employmentId,
                        ),
                        previous: $exception,
                    );
                }

                throw $exception;
            }
        });
    }
}
