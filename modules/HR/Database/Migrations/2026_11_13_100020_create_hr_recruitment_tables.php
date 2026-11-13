<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rekrutmen sederhana (PRD-001 §20, HR-9a; OD-HR-19 s.d. OD-HR-21).
 *
 * - `hr_vacancies`: lowongan per lembaga tujuan & jabatan; Draf → Dibuka → Ditutup.
 * - `hr_applicants`: pelamar yang diinput Admin SDM; BUKAN Person/anggota yayasan sampai diterima jadi pegawai (9b).
 *   Tahap tetap: BERKAS → TES → DITERIMA / TIDAK_LOLOS. Berkas lamaran opsional di disk privat.
 * - `hr_applicant_steps`: riwayat aksi seleksi & catatan wawancara (nilai 0–100 opsional).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_vacancies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('title', 150);
            $table->uuid('organization_id');
            $table->uuid('position_id');
            $table->unsignedSmallInteger('openings');
            $table->date('closes_on')->nullable();
            $table->text('requirements')->nullable();
            $table->string('status', 10)->default('DRAF');
            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->uuid('created_by_membership_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'organization_id']);

            $table->foreign(['tenant_id', 'organization_id'])->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'position_id'])->references(['tenant_id', 'id'])->on('hr_positions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'created_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE hr_vacancies ADD CONSTRAINT hr_vacancies_status_check CHECK (status IN ('DRAF', 'DIBUKA', 'DITUTUP'))");
        DB::statement('ALTER TABLE hr_vacancies ADD CONSTRAINT hr_vacancies_openings_check CHECK (openings BETWEEN 1 AND 99)');
        DB::statement('ALTER TABLE hr_vacancies ADD CONSTRAINT hr_vacancies_requirements_check CHECK (requirements IS NULL OR char_length(requirements) <= 2000)');

        Schema::create('hr_applicants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('vacancy_id');
            $table->string('name', 200);
            $table->char('gender', 1);
            $table->string('phone', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('education_level', 10)->nullable();
            $table->string('education_major', 150)->nullable();
            $table->string('note', 500)->nullable();
            $table->string('stage', 12)->default('BERKAS');

            // Berkas lamaran (opsional). Nama asli hanya untuk tampilan & nama unduhan.
            $table->string('storage_path', 255)->nullable();
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('byte_size')->nullable();
            $table->char('sha256', 64)->nullable();

            $table->uuid('created_by_membership_id')->nullable();
            $table->timestamps();

            $table->unique('storage_path');
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'vacancy_id', 'stage']);

            $table->foreign(['tenant_id', 'vacancy_id'])->references(['tenant_id', 'id'])->on('hr_vacancies')->restrictOnDelete();
            $table->foreign(['tenant_id', 'created_by_membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE hr_applicants ADD CONSTRAINT hr_applicants_stage_check CHECK (stage IN ('BERKAS', 'TES', 'DITERIMA', 'TIDAK_LOLOS'))");
        DB::statement("ALTER TABLE hr_applicants ADD CONSTRAINT hr_applicants_gender_check CHECK (gender IN ('L', 'P'))");
        DB::statement("ALTER TABLE hr_applicants ADD CONSTRAINT hr_applicants_phone_check CHECK (phone IS NULL OR phone ~ '^\\+?[0-9]{8,19}$')");
        DB::statement("ALTER TABLE hr_applicants ADD CONSTRAINT hr_applicants_file_check CHECK ((storage_path IS NULL) = (sha256 IS NULL) AND (sha256 IS NULL OR (byte_size > 0 AND sha256 ~ '^[0-9a-f]{64}$')))");

        Schema::create('hr_applicant_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('applicant_id');
            $table->string('action', 12);
            // Tahap pelamar saat aksi dicatat.
            $table->string('stage', 12);
            $table->unsignedSmallInteger('score')->nullable();
            $table->string('note', 500)->nullable();
            $table->uuid('membership_id')->nullable();
            $table->timestampTz('created_at');

            $table->index(['applicant_id', 'created_at']);

            $table->foreign(['tenant_id', 'applicant_id'])->references(['tenant_id', 'id'])->on('hr_applicants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE hr_applicant_steps ADD CONSTRAINT hr_applicant_steps_action_check CHECK (action IN ('LOLOS_BERKAS', 'DITERIMA', 'TIDAK_LOLOS', 'KEMBALI', 'CATATAN'))");
        DB::statement('ALTER TABLE hr_applicant_steps ADD CONSTRAINT hr_applicant_steps_score_check CHECK (score IS NULL OR score <= 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_applicant_steps');
        Schema::dropIfExists('hr_applicants');
        Schema::dropIfExists('hr_vacancies');
    }
};
