<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-003 §7.1 — "One approved recruitment need/posting within a Tenant."
 *
 * PENTING: `organization_id`/`organization_unit_id` di tabel ini BUKAN
 * pelanggaran terhadap prinsip "Employment Placement adalah satu-satunya
 * sumber kebenaran penempatan" yang kita tegakkan ketat di RM-HR-01.
 * HR-003 §7.1 menjelaskannya eksplisit: "Vacancy owns a recruitment
 * TARGET, not Employee placement truth" (INV-REC-010 — "Vacancy
 * placement is intent only"). Artinya kolom ini cuma niat/target
 * lowongan ("kita mau merekrut untuk unit X"), bukan pernyataan bahwa
 * seseorang sudah ditempatkan di sana — itu baru terjadi lewat
 * EmploymentPlacement setelah hire conversion & onboarding selesai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_vacancies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 50);
            $table->string('title', 255);
            $table->uuid('position_id');
            $table->uuid('organization_id');
            $table->uuid('organization_unit_id')->nullable();
            $table->integer('requested_headcount');
            $table->text('description')->nullable();
            $table->string('status', 24)->default('DRAFT');
            $table->timestampTz('open_at')->nullable();
            $table->timestampTz('close_at')->nullable();
            $table->uuid('created_by_membership_id');
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'code'],
                'uq_recruitment_vacancies_tenant_code',
            );

            // Supporting key untuk composite FK dari
            // recruitment_vacancy_stages & recruitment_applications
            // (step berikutnya): (vacancy_id, tenant_id).
            $table->unique(
                ['id', 'tenant_id'],
                'uq_recruitment_vacancies_id_tenant',
            );

            $table->index(
                ['tenant_id', 'status'],
                'idx_recruitment_vacancies_tenant_status',
            );
            $table->index(
                ['tenant_id', 'organization_id', 'status'],
                'idx_recruitment_vacancies_organization_status',
            );

            $table->foreign(
                ['position_id', 'tenant_id'],
                'fk_recruitment_vacancies_position_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('positions')
                ->restrictOnDelete();

            $table->foreign(
                ['organization_id', 'tenant_id'],
                'fk_recruitment_vacancies_organization_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('organizations')
                ->restrictOnDelete();

            // Composite tiga kolom mengikuti persis supporting key yang
            // sudah ada di organization_units (uq_org_units_identity_scope
            // = id, organization_id, tenant_id) — nullable-safe: FK ini
            // "diam" ketika organization_unit_id NULL (target tingkat
            // organisasi, bukan unit spesifik).
            $table->foreign(
                ['organization_unit_id', 'organization_id', 'tenant_id'],
                'fk_recruitment_vacancies_unit_org_tenant',
            )
                ->references(['id', 'organization_id', 'tenant_id'])
                ->on('organization_units')
                ->restrictOnDelete();

            // Simple FK ke memberships.id — mengikuti konvensi yang
            // sudah ada persis di employees.membership_id (tidak ada
            // composite tenant-safe unique key di memberships untuk
            // dijadikan target FK tiga-kolom). Konsistensi tenant tetap
            // divalidasi di service layer, bukan di database untuk
            // kolom actor-reference seperti ini.
            $table->foreign(
                'created_by_membership_id',
                'fk_recruitment_vacancies_created_by_membership',
            )
                ->references('id')
                ->on('memberships')
                ->restrictOnDelete();
        });

        DB::statement(
            <<<'SQL'
                ALTER TABLE recruitment_vacancies
                ADD CONSTRAINT chk_recruitment_vacancies_requested_headcount
                CHECK (requested_headcount > 0)
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE recruitment_vacancies
                ADD CONSTRAINT chk_recruitment_vacancies_close_after_open
                CHECK (
                    close_at IS NULL
                    OR open_at IS NULL
                    OR close_at >= open_at
                )
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE recruitment_vacancies
                ADD CONSTRAINT chk_recruitment_vacancies_status
                CHECK (status IN (
                    'DRAFT',
                    'PENDING_APPROVAL',
                    'APPROVED',
                    'OPEN',
                    'CLOSED',
                    'CANCELLED'
                ))
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_vacancies');
    }
};
