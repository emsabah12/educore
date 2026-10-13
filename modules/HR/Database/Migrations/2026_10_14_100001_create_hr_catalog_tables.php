<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Katalog SDM per yayasan (PRD-001 §4.3): jenis pegawai, status kepegawaian, jabatan.
 *
 * Ketiganya sengaja berbentuk sama. Item tidak pernah dihapus; cukup dinonaktifkan agar
 * riwayat yang merujuknya tetap utuh. Kode tidak bisa diubah setelah dibuat.
 */
return new class extends Migration
{
    private const TABLES = [
        'hr_employment_types',
        'hr_employment_classifications',
        'hr_positions',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
                $table->string('code', 30);
                $table->string('name', 100);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['tenant_id', 'code']);

                // Target foreign key gabungan dari tabel kepegawaian: rujukan wajib dari yayasan yang sama.
                $table->unique(['tenant_id', 'id']);
            });

            DB::statement("ALTER TABLE {$tableName} ADD CONSTRAINT {$tableName}_code_format_check CHECK (code ~ '^[A-Z0-9][A-Z0-9-]{1,29}$')");
            DB::statement("ALTER TABLE {$tableName} ADD CONSTRAINT {$tableName}_name_not_blank_check CHECK (btrim(name) <> '')");
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $tableName) {
            Schema::dropIfExists($tableName);
        }
    }
};
