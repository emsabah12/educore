<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR-002 §5.6 — "To support the second composite FK, Core should add an
 * integrity-only supporting constraint: UNIQUE (id, tenant_id) ON
 * organizational_assignments. This is classified EXTEND — integrity
 * support only and does not alter Core ownership/cardinality."
 *
 * Migration ini SENGAJA ditempatkan di dalam module HR (bukan Core),
 * persis seperti pola yang sama dipakai untuk `employees` di Step 3.
 * Alasannya: constraint ini ada semata-mata supaya HR bisa membuat
 * composite foreign key yang aman-tenant dari `employment_placements` ke
 * `organizational_assignments` milik Core. Ini bukan perubahan terhadap
 * domain ownership atau cardinality Core Organization — murni "integrity
 * support key" tambahan yang dibutuhkan oleh konsumen (HR), sehingga
 * tetap masuk akal untuk hidup di migration path HR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizational_assignments', function (Blueprint $table): void {
            $table->unique(
                ['id', 'tenant_id'],
                'uq_org_assignments_id_tenant',
            );
        });
    }

    public function down(): void
    {
        Schema::table('organizational_assignments', function (Blueprint $table): void {
            $table->dropUnique('uq_org_assignments_id_tenant');
        });
    }
};
