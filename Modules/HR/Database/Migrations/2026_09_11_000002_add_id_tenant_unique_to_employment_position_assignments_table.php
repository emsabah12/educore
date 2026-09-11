<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `employment_position_assignments` sebelumnya hanya punya PRIMARY
 * KEY tunggal di `id` — cukup untuk FK sederhana, tapi TIDAK cukup
 * untuk FK komposit tenant-safe seperti yang dipakai di seluruh
 * modul HR lain (mis. `uq_employments_id_tenant`,
 * `uq_leave_requests_id_tenant`).
 *
 * HR-006 §7.3 membutuhkan `compensation_assignments.
 * employment_position_assignment_id` sebagai FK komposit
 * `(employment_position_assignment_id, tenant_id)` — supaya baris
 * Position Assignment yang direferensikan dijamin benar-benar milik
 * tenant yang sama, bukan cuma ID yang kebetulan cocok lintas-tenant.
 * Migration ini murni menambah supporting unique index, TIDAK
 * mengubah struktur/perilaku existing sama sekali.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
                ALTER TABLE employment_position_assignments
                ADD CONSTRAINT uq_emp_position_assignments_id_tenant
                UNIQUE (id, tenant_id)
                SQL,
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE employment_position_assignments DROP CONSTRAINT IF EXISTS uq_emp_position_assignments_id_tenant',
        );
    }
};
