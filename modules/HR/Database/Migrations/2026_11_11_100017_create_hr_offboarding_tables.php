<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Offboarding pegawai keluar (PRD-001 §18.2, HR-7b).
 *
 * - `hr_offboarding_items`: katalog item checklist per yayasan, bentuknya sama dengan katalog SDM lain
 *   (kode tetap, nama bisa diubah, tidak dihapus — dinonaktifkan).
 * - `hr_offboardings`: satu checklist per periode kepegawaian yang ditutup karena berhenti.
 * - `hr_offboarding_tasks`: item checklist (salinan kode & nama saat dibuat) + status BELUM/SELESAI/TIDAK_PERLU.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_offboarding_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
        });

        DB::statement("ALTER TABLE hr_offboarding_items ADD CONSTRAINT hr_offboarding_items_code_format_check CHECK (code ~ '^[A-Z0-9][A-Z0-9-]{1,29}$')");
        DB::statement("ALTER TABLE hr_offboarding_items ADD CONSTRAINT hr_offboarding_items_name_not_blank_check CHECK (btrim(name) <> '')");

        Schema::create('hr_offboardings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('employee_id');
            $table->uuid('employment_period_id');
            $table->date('end_date');
            $table->uuid('created_by_membership_id')->nullable();
            $table->timestamps();

            $table->unique('employment_period_id');
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'end_date']);

            $table->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('hr_employees')->restrictOnDelete();
            $table->foreign('employment_period_id')->references('id')->on('hr_employment_periods')->restrictOnDelete();
            $table->foreign(['tenant_id', 'created_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        Schema::create('hr_offboarding_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('offboarding_id');
            $table->uuid('item_id');
            $table->string('code', 30);
            $table->string('name', 100);
            $table->unsignedSmallInteger('position');
            $table->string('status', 12)->default('BELUM');
            $table->string('note', 300)->nullable();
            $table->uuid('done_by_membership_id')->nullable();
            $table->timestampTz('done_at')->nullable();
            $table->timestamps();

            $table->unique(['offboarding_id', 'item_id']);

            $table->foreign(['tenant_id', 'offboarding_id'])->references(['tenant_id', 'id'])->on('hr_offboardings')->restrictOnDelete();
            $table->foreign(['tenant_id', 'item_id'])->references(['tenant_id', 'id'])->on('hr_offboarding_items')->restrictOnDelete();
            $table->foreign(['tenant_id', 'done_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE hr_offboarding_tasks ADD CONSTRAINT hr_offboarding_tasks_status_check CHECK (status IN ('BELUM', 'SELESAI', 'TIDAK_PERLU'))");
        DB::statement("ALTER TABLE hr_offboarding_tasks ADD CONSTRAINT hr_offboarding_tasks_done_check CHECK ((status = 'BELUM') = (done_at IS NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_offboarding_tasks');
        Schema::dropIfExists('hr_offboardings');
        Schema::dropIfExists('hr_offboarding_items');
    }
};
