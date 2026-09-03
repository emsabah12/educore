<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR-002 §14 Step 2 — "Add integrity supporting keys".
 *
 * `employees.id` sudah unik secara alami sebagai primary key, tapi tabel
 * `employments` yang akan dibuat setelah ini butuh melakukan composite
 * foreign key ke (employee_id, tenant_id) — bukan cuma employee_id — supaya
 * database secara aktif menolak baris employments yang mereferensikan
 * Employee dari tenant lain. Composite foreign key seperti itu HANYA bisa
 * dibuat kalau tabel tujuan (employees) punya composite UNIQUE/PK yang
 * persis sama pasangan kolomnya. Migration ini murni menambahkan
 * "integrity support key" tersebut, tidak mengubah cardinality atau
 * perilaku Employee yang sudah ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->unique(
                ['id', 'tenant_id'],
                'uq_employees_id_tenant',
            );
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropUnique('uq_employees_id_tenant');
        });
    }
};
