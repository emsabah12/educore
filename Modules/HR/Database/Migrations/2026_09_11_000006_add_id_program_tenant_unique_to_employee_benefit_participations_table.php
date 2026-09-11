<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR-006 §7.7: "Application/DB tenant-safe validation must ensure
 * `benefit_program_id` equals the program of the referenced
 * participation."
 *
 * Migration ini menambah supporting unique key `(id,
 * benefit_program_id, tenant_id)` ke `employee_benefit_participations`
 * — TIDAK mengubah perilaku existing sama sekali — supaya invariant
 * itu bisa ditegakkan sebagai FK KOMPOSIT 3-KOLOM sungguhan di
 * `employee_benefit_identifiers` (migration berikutnya), bukan cuma
 * validasi aplikasi. Pola sama persis dengan
 * `employment_placement_id, employment_id, tenant_id` 3-kolom di
 * `employment_position_assignments`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
                ALTER TABLE employee_benefit_participations
                ADD CONSTRAINT uq_benefit_participations_id_program_tenant
                UNIQUE (id, benefit_program_id, tenant_id)
                SQL,
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE employee_benefit_participations DROP CONSTRAINT IF EXISTS uq_benefit_participations_id_program_tenant',
        );
    }
};
