<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Profil pegawai (PRD-001 §4.3). Satu profil per membership (orang × yayasan).
 *
 * Nama dan data pribadi ada di Person; tabel ini hanya menyimpan data kepegawaian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('membership_id');
            $table->string('employee_number', 30)->nullable();
            $table->string('nuptk', 16)->nullable();
            $table->string('education_level', 10)->nullable();
            $table->string('education_major', 150)->nullable();
            $table->string('status', 20)->default('AKTIF');
            $table->timestamps();

            // Satu orang hanya punya satu profil pegawai di sebuah yayasan (PRD-001 §4.3, [LAMA] BR-003).
            $table->unique('membership_id');
            $table->unique(['tenant_id', 'employee_number']);
            $table->unique(['tenant_id', 'nuptk']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status']);

            // Membership wajib dari yayasan yang sama.
            $table->foreign(['tenant_id', 'membership_id'])
                ->references(['tenant_id', 'id'])
                ->on('memberships')
                ->restrictOnDelete();
        });

        DB::statement("ALTER TABLE hr_employees ADD CONSTRAINT hr_employees_status_check CHECK (status IN ('AKTIF', 'BERHENTI'))");
        DB::statement("ALTER TABLE hr_employees ADD CONSTRAINT hr_employees_nuptk_format_check CHECK (nuptk IS NULL OR nuptk ~ '^[0-9]{16}$')");
        // [ASUMSI] Nomor induk yayasan: huruf besar, angka, titik, garis miring, tanda "-".
        DB::statement("ALTER TABLE hr_employees ADD CONSTRAINT hr_employees_number_format_check CHECK (employee_number IS NULL OR employee_number ~ '^[A-Z0-9][A-Z0-9./-]{0,29}$')");
        DB::statement(<<<'SQL'
            ALTER TABLE hr_employees ADD CONSTRAINT hr_employees_education_level_check CHECK (
                education_level IS NULL
                OR education_level IN ('SD', 'SMP', 'SMA', 'D1', 'D2', 'D3', 'D4_S1', 'S2', 'S3', 'LAINNYA')
            )
        SQL);
        DB::statement("ALTER TABLE hr_employees ADD CONSTRAINT hr_employees_education_major_not_blank_check CHECK (education_major IS NULL OR btrim(education_major) <> '')");
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employees');
    }
};
