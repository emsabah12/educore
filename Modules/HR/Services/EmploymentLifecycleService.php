<?php

declare(strict_types=1);

namespace Modules\HR\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\HR\Exceptions\EmploymentLifecycleException;
use Modules\HR\Models\Employment;
use Modules\HR\Models\EmploymentClassification;
use Modules\HR\Models\EmploymentType;
use Modules\HR\Services\Concerns\LocksEmploymentRecords;
use Carbon\Carbon;
use Modules\HR\Models\EmploymentPlacement;
use Modules\HR\Models\EmploymentPositionAssignment;

/**
 * Implementasi algoritma transaksi Employment lifecycle dari HR-002 §9.
 *
 * Kelas ini SENGAJA tidak menyentuh Employment Placement atau Position
 * Assignment karena kedua tabel itu belum ada (rencana Step berikutnya).
 * Karena itu method `end()` (§9.4, yang wajib menutup Placement & Position
 * Assignment terbuka / INV-HR-008) BELUM disertakan di sini — akan
 * ditambahkan setelah tabel pendukungnya siap, supaya kita tidak menulis
 * "end" versi setengah-jadi yang melanggar invariant.
 */
final readonly class EmploymentLifecycleService
{
    use LocksEmploymentRecords;

    /**
     * HR-002 §10.2 `POST /employees/{employeeId}/employments`.
     *
     * Selalu membuat Employment berstatus PLANNED. Transisi ke ACTIVE
     * adalah langkah eksplisit terpisah lewat `activate()`.
     *
     * @param array{
     *     employment_type_id?: string|null,
     *     employment_classification_id?: string|null,
     *     start_date: string,
     * } $data
     */
    public function createPlanned(
        string $tenantId,
        string $employeeId,
        array $data,
    ): Employment {
        return DB::transaction(function () use (
            $tenantId,
            $employeeId,
            $data,
        ): Employment {
            $employee = $this->lockEmployeeForTenant(
                $employeeId,
                $tenantId,
            );

            $this->requireActiveMembership(
                $employee->membership_id,
                $tenantId,
            );

            $employmentTypeId = $data['employment_type_id'] ?? null;
            $employmentClassificationId = $data['employment_classification_id'] ?? null;

            if ($employmentTypeId !== null) {
                $this->requireActiveCatalogEntry(
                    EmploymentType::class,
                    $employmentTypeId,
                    $tenantId,
                    'employment_type_id',
                );
            }

            if ($employmentClassificationId !== null) {
                $this->requireActiveCatalogEntry(
                    EmploymentClassification::class,
                    $employmentClassificationId,
                    $tenantId,
                    'employment_classification_id',
                );
            }

            return Employment::create([
                'employee_id' => $employee->id,
                'employment_type_id' => $employmentTypeId,
                'employment_classification_id' => $employmentClassificationId,
                'status' => Employment::STATUS_PLANNED,
                'start_date' => $data['start_date'],
            ]);
        });
    }

    /**
     * HR-002 §9.1 — Activate Employment transaction algorithm.
     */
    public function activate(
        string $tenantId,
        string $employmentId,
    ): Employment {
        return DB::transaction(function () use (
            $tenantId,
            $employmentId,
        ): Employment {
            $employment = $this->lockEmploymentForTenant(
                $employmentId,
                $tenantId,
            );

            $employee = $this->lockEmployeeForTenant(
                $employment->employee_id,
                $tenantId,
            );

            $this->requireActiveMembership(
                $employee->membership_id,
                $tenantId,
            );

            if ($employment->status !== Employment::STATUS_PLANNED) {
                throw new EmploymentLifecycleException(
                    sprintf(
                        'Employment [%s] cannot be activated from status [%s]. Only PLANNED employment may transition to ACTIVE.',
                        $employmentId,
                        $employment->status,
                    ),
                );
            }

            if ($employment->employment_type_id === null) {
                throw new EmploymentLifecycleException(
                    sprintf(
                        'Employment [%s] cannot be activated without an employment_type_id.',
                        $employmentId,
                    ),
                );
            }

            $this->requireActiveCatalogEntry(
                EmploymentType::class,
                $employment->employment_type_id,
                $tenantId,
                'employment_type_id',
            );

            if ($employment->employment_classification_id !== null) {
                $this->requireActiveCatalogEntry(
                    EmploymentClassification::class,
                    $employment->employment_classification_id,
                    $tenantId,
                    'employment_classification_id',
                );
            }

            $hasOtherActiveEmployment = Employment::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('employee_id', $employment->employee_id)
                ->where('status', Employment::STATUS_ACTIVE)
                ->where('id', '!=', $employment->id)
                ->exists();

            if ($hasOtherActiveEmployment) {
                throw new EmploymentLifecycleException(
                    sprintf(
                        'Employee [%s] already has an ACTIVE employment. Only one ACTIVE employment is allowed at a time (INV-HR-002).',
                        $employment->employee_id,
                    ),
                );
            }

            try {
                $employment->status = Employment::STATUS_ACTIVE;
                $employment->save();
            } catch (QueryException $exception) {
                if ($this->isActiveEmploymentConflict($exception)) {
                    throw new EmploymentLifecycleException(
                        sprintf(
                            'Employee [%s] already has an ACTIVE employment (concurrent activation detected).',
                            $employment->employee_id,
                        ),
                        previous: $exception,
                    );
                }

                throw $exception;
            }

            return $employment->refresh();
        });
    }

    /**
     * HR-002 §9.4 — End Employment transaction algorithm.
     *
     * "Single transaction: Employment ACTIVE -> lock -> close open
     * Position Assignments -> close open Employment Placements ->
     * Employment -> ENDED + end_date -> audit after successful
     * persistence boundary."
     *
     * INV-HR-008: mengakhiri Employment WAJIB menutup semua Placement &
     * Position Assignment yang masih terbuka secara ATOMIK (satu
     * transaksi yang sama) — bukan dua operasi terpisah yang bisa gagal
     * di tengah jalan dan meninggalkan data setengah-konsisten.
     *
     * INV-HR-006: menutup Placement di sini TIDAK menonaktifkan Core
     * OrganizationalAssignment — assignment itu tetap ACTIVE di Core
     * karena mungkin masih dipakai domain lain untuk otorisasi.
     */
    public function end(
        string $tenantId,
        string $employmentId,
        string $endDate,
    ): Employment {
        return DB::transaction(function () use (
            $tenantId,
            $employmentId,
            $endDate,
        ): Employment {
            $employment = $this->lockEmploymentForTenant(
                $employmentId,
                $tenantId,
            );

            if ($employment->status !== Employment::STATUS_ACTIVE) {
                throw new EmploymentLifecycleException(
                    sprintf(
                        'Employment [%s] cannot be ended from status [%s]. Only ACTIVE employment may be ended.',
                        $employmentId,
                        $employment->status,
                    ),
                );
            }

            $endDateParsed = Carbon::parse($endDate);

            if ($endDateParsed->lt($employment->start_date)) {
                throw new EmploymentLifecycleException(
                    sprintf(
                        'end_date [%s] cannot be earlier than Employment start_date [%s].',
                        $endDateParsed->toDateString(),
                        $employment->start_date->toDateString(),
                    ),
                );
            }

            if ($endDateParsed->gt(Carbon::today())) {
                throw new EmploymentLifecycleException(
                    'end_date cannot be in the future. Scheduled/future ending is not supported in Phase 2A.',
                );
            }

            try {
                // INV-HR-008, urutan penutupan: Position Assignment
                // ditutup LEBIH DULU, baru Placement — mencerminkan
                // hierarki ketergantungan (INV-HR-007: sebuah Position
                // Assignment tidak boleh "hidup lebih lama" dari
                // Placement yang menjadi scope-nya).
                EmploymentPositionAssignment::query()
                    ->withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenantId)
                    ->where('employment_id', $employmentId)
                    ->whereNull('effective_to')
                    ->update([
                        'effective_to' => $endDateParsed->toDateString(),
                    ]);

                EmploymentPlacement::query()
                    ->withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenantId)
                    ->where('employment_id', $employmentId)
                    ->whereNull('effective_to')
                    ->update([
                        'effective_to' => $endDateParsed->toDateString(),
                    ]);

                $employment->status = Employment::STATUS_ENDED;
                $employment->end_date = $endDateParsed->toDateString();
                $employment->save();
            } catch (QueryException $exception) {
                // Jaring pengaman: kalau ada Placement/Position
                // Assignment yang effective_from-nya kebetulan LEBIH
                // BARU dari end_date yang diminta (kasus tepi yang jarang
                // terjadi tapi mungkin), CHECK constraint
                // (`..._effective_to_after_from`) akan menolaknya. Kita
                // terjemahkan jadi pesan domain yang jelas, bukan
                // meloloskan error database mentah.
                if (
                    str_contains($exception->getMessage(), 'chk_employment_placements_effective_to_after_from')
                    || str_contains($exception->getMessage(), 'chk_emp_position_assignments_effective_to_after_from')
                ) {
                    throw new EmploymentLifecycleException(
                        sprintf(
                            'end_date [%s] is earlier than the effective_from of an open Placement or Position Assignment under this Employment.',
                            $endDateParsed->toDateString(),
                        ),
                        previous: $exception,
                    );
                }

                throw $exception;
            }

            return $employment->refresh();
        });
    }

    /**
     * HR-002 §10.2 `POST /employments/{employmentId}/cancel`.
     */
    public function cancel(
        string $tenantId,
        string $employmentId,
    ): Employment {
        return DB::transaction(function () use (
            $tenantId,
            $employmentId,
        ): Employment {
            $employment = $this->lockEmploymentForTenant(
                $employmentId,
                $tenantId,
            );

            if ($employment->status !== Employment::STATUS_PLANNED) {
                throw new EmploymentLifecycleException(
                    sprintf(
                        'Employment [%s] cannot be cancelled from status [%s]. Only PLANNED employment may be cancelled.',
                        $employmentId,
                        $employment->status,
                    ),
                );
            }

            $employment->status = Employment::STATUS_CANCELLED;
            $employment->cancelled_at = now();
            $employment->save();

            return $employment->refresh();
        });
    }

    private function requireActiveMembership(
        string $membershipId,
        string $tenantId,
    ): void {
        $isActive = DB::table('memberships')
            ->where('id', $membershipId)
            ->where('tenant_id', $tenantId)
            ->where('status', 'ACTIVE')
            ->exists();

        if (! $isActive) {
            throw new EmploymentLifecycleException(
                sprintf(
                    'Membership [%s] is not ACTIVE in tenant [%s].',
                    $membershipId,
                    $tenantId,
                ),
            );
        }
    }

    /**
     * @param class-string<EmploymentType>|class-string<EmploymentClassification> $modelClass
     */
    private function requireActiveCatalogEntry(
        string $modelClass,
        string $catalogId,
        string $tenantId,
        string $fieldName,
    ): void {
        $isActive = $modelClass::query()
            ->withoutGlobalScope('tenant')
            ->where('id', $catalogId)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->exists();

        if (! $isActive) {
            throw new EmploymentLifecycleException(
                sprintf(
                    '%s [%s] does not reference an active catalog entry in tenant [%s].',
                    $fieldName,
                    $catalogId,
                    $tenantId,
                ),
            );
        }
    }

    private function isActiveEmploymentConflict(QueryException $exception): bool
    {
        return str_contains(
            $exception->getMessage(),
            'uq_employments_active_employee',
        );
    }
}
