<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-003 §7.14 — "Semantic-idempotency record and immutable conversion
 * result evidence."
 *
 * "Repeated successful conversion requests return the existing
 * successful result rather than creating new domain rows." Tabel ini
 * adalah jantung dari HireConversionService (Step 4): sebelum mengubah
 * apa pun (Person/Membership/Employee/Employment), service SELALU
 * lock/create baris ini dulu dan cek apakah conversion_status sudah
 * SUCCEEDED — kalau sudah, langsung kembalikan hasil yang SAMA, bukan
 * mengulang mutasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_hire_conversions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('application_id');
            $table->string('resolution_status', 24)->default('UNRESOLVED');
            $table->string('conversion_status', 16)->default('PENDING');
            $table->uuid('person_id')->nullable();
            $table->uuid('membership_id')->nullable();
            $table->uuid('employee_id')->nullable();
            $table->uuid('employment_id')->nullable();
            $table->uuid('resolved_by_membership_id')->nullable();
            $table->uuid('converted_by_membership_id')->nullable();
            $table->timestampTz('converted_at')->nullable();
            $table->timestamps();

            // "exactly one conversion record per Application."
            $table->unique(
                ['tenant_id', 'application_id'],
                'uq_recruitment_hire_conversions_tenant_application',
            );

            $table->index(
                ['tenant_id', 'conversion_status'],
                'idx_recruitment_hire_conversions_tenant_status',
            );

            $table->foreign(
                ['application_id', 'tenant_id'],
                'fk_recruitment_hire_conversions_application_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('recruitment_applications')
                ->restrictOnDelete();

            // person_id/membership_id: simple FK — Person/Membership
            // adalah identitas Core, sama seperti pola
            // employees.membership_id yang sudah dipakai sejak RM-HR-01.
            $table->foreign('person_id', 'fk_recruitment_hire_conversions_person')
                ->references('id')
                ->on('persons')
                ->restrictOnDelete();

            $table->foreign('membership_id', 'fk_recruitment_hire_conversions_membership')
                ->references('id')
                ->on('memberships')
                ->restrictOnDelete();

            $table->foreign(
                ['employee_id', 'tenant_id'],
                'fk_recruitment_hire_conversions_employee_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('employees')
                ->restrictOnDelete();

            $table->foreign(
                ['employment_id', 'tenant_id'],
                'fk_recruitment_hire_conversions_employment_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('employments')
                ->restrictOnDelete();

            $table->foreign(
                'resolved_by_membership_id',
                'fk_recruitment_hire_conversions_resolved_by',
            )
                ->references('id')
                ->on('memberships')
                ->restrictOnDelete();

            $table->foreign(
                'converted_by_membership_id',
                'fk_recruitment_hire_conversions_converted_by',
            )
                ->references('id')
                ->on('memberships')
                ->restrictOnDelete();
        });

        DB::statement(
            <<<'SQL'
                ALTER TABLE recruitment_hire_conversions
                ADD CONSTRAINT chk_recruitment_hire_conversions_resolution_status
                CHECK (resolution_status IN (
                    'UNRESOLVED',
                    'MATCHED_EXISTING',
                    'CREATE_NEW_CONFIRMED',
                    'CONFLICT'
                ))
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE recruitment_hire_conversions
                ADD CONSTRAINT chk_recruitment_hire_conversions_conversion_status
                CHECK (conversion_status IN ('PENDING', 'SUCCEEDED', 'CANCELLED'))
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE recruitment_hire_conversions
                ADD CONSTRAINT chk_recruitment_hire_conversions_succeeded_has_results
                CHECK (
                    conversion_status <> 'SUCCEEDED'
                    OR (
                        person_id IS NOT NULL
                        AND membership_id IS NOT NULL
                        AND employee_id IS NOT NULL
                        AND employment_id IS NOT NULL
                        AND converted_at IS NOT NULL
                    )
                )
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_hire_conversions');
    }
};
