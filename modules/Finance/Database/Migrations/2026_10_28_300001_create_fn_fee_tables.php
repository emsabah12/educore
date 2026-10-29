<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jenis biaya & tarif siswa (PRD-003 §4, FN-1a).
 *
 * - `fn_fee_types`: katalog jenis biaya per yayasan. Sifat (BULANAN/SEKALI) tidak bisa diubah setelah
 *   dibuat. Tidak dihapus; dinonaktifkan.
 * - `fn_fee_rates`: tarif jenis biaya × lembaga × tahun ajaran, opsional per tingkat (1–12). Tarif tanpa
 *   tingkat berlaku untuk semua tingkat; tarif bertingkat didahulukan (OD-FN-04). Rupiah bulat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fn_fee_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->string('kind', 10);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
        });

        DB::statement("ALTER TABLE fn_fee_types ADD CONSTRAINT fn_fee_types_code_format_check CHECK (code ~ '^[A-Z0-9][A-Z0-9-]{1,29}$')");
        DB::statement("ALTER TABLE fn_fee_types ADD CONSTRAINT fn_fee_types_name_not_blank_check CHECK (btrim(name) <> '')");
        DB::statement("ALTER TABLE fn_fee_types ADD CONSTRAINT fn_fee_types_kind_check CHECK (kind IN ('BULANAN', 'SEKALI'))");

        Schema::create('fn_fee_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('fee_type_id');
            $table->uuid('organization_id');
            $table->uuid('academic_year_id');
            $table->unsignedSmallInteger('grade')->nullable();
            $table->bigInteger('amount');
            $table->uuid('updated_by_membership_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'organization_id', 'academic_year_id']);

            $table->foreign(['tenant_id', 'fee_type_id'])->references(['tenant_id', 'id'])->on('fn_fee_types')->restrictOnDelete();
            $table->foreign(['tenant_id', 'organization_id'])->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_year_id'])->references(['tenant_id', 'id'])->on('ac_academic_years')->restrictOnDelete();
            $table->foreign(['tenant_id', 'updated_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        // Satu tarif per jenis × lembaga × tahun ajaran × tingkat; "semua tingkat" (grade kosong) juga satu.
        DB::statement('CREATE UNIQUE INDEX fn_fee_rates_graded_unique ON fn_fee_rates (fee_type_id, organization_id, academic_year_id, grade) WHERE grade IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX fn_fee_rates_all_grades_unique ON fn_fee_rates (fee_type_id, organization_id, academic_year_id) WHERE grade IS NULL');
        DB::statement('ALTER TABLE fn_fee_rates ADD CONSTRAINT fn_fee_rates_grade_check CHECK (grade IS NULL OR grade BETWEEN 1 AND 12)');
        // Batas Rp 100 juta per tagihan: menangkap salah ketik (kelebihan nol) [ASUMSI].
        DB::statement('ALTER TABLE fn_fee_rates ADD CONSTRAINT fn_fee_rates_amount_check CHECK (amount BETWEEN 1 AND 100000000)');
    }

    public function down(): void
    {
        Schema::dropIfExists('fn_fee_rates');
        Schema::dropIfExists('fn_fee_types');
    }
};
