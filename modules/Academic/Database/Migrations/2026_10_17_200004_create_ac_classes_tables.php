<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kelas/rombongan belajar dan anggotanya (PRD-002 §4.2).
 *
 * - Kelas milik satu lembaga pada satu tahun ajaran. Tahun ajaran harus dari kalender yang
 *   berlaku untuk lembaga itu (§4.4) — dicek service.
 * - Wali kelas = pegawai HR (FK ke hr_employees). Syarat "aktif dan ditempatkan di lembaga itu"
 *   dicek service lewat kontrak publik HR. Database hanya menjamin pegawai dari yayasan yang sama.
 * - Anggota kelas menyimpan organization_id & academic_year_id kelasnya, sehingga database bisa
 *   menjamin (1) kelas dan pendaftaran di lembaga yang sama dan (2) satu pendaftaran hanya di
 *   satu kelas terbuka per tahun ajaran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ac_classes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->uuid('academic_year_id');
            $table->unsignedSmallInteger('grade');
            $table->string('name', 50);
            $table->uuid('homeroom_employee_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'organization_id', 'academic_year_id']);
            $table->index(['tenant_id', 'homeroom_employee_id']);

            // Target foreign key gabungan dari ac_class_members.
            $table->unique(['tenant_id', 'organization_id', 'academic_year_id', 'id']);

            $table->foreign(['tenant_id', 'organization_id'])
                ->references(['tenant_id', 'id'])->on('organizations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_year_id'])
                ->references(['tenant_id', 'id'])->on('ac_academic_years')->restrictOnDelete();
            // homeroom_employee_id kosong → foreign key tidak diperiksa (MATCH SIMPLE).
            $table->foreign(['tenant_id', 'homeroom_employee_id'])
                ->references(['tenant_id', 'id'])->on('hr_employees')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE ac_classes ADD CONSTRAINT ac_classes_grade_check CHECK (grade BETWEEN 1 AND 12)');
        DB::statement("ALTER TABLE ac_classes ADD CONSTRAINT ac_classes_name_not_blank_check CHECK (btrim(name) <> '')");
        // Nama rombel unik per lembaga per tahun ajaran, tanpa beda huruf besar/kecil.
        DB::statement('CREATE UNIQUE INDEX ac_classes_name_unique ON ac_classes (organization_id, academic_year_id, lower(name))');

        Schema::create('ac_class_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->uuid('academic_year_id');
            $table->uuid('class_id');
            $table->uuid('enrollment_id');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->timestamps();

            $table->index(['class_id', 'end_date']);
            $table->index(['enrollment_id', 'end_date']);

            $table->foreign(['tenant_id', 'organization_id', 'academic_year_id', 'class_id'])
                ->references(['tenant_id', 'organization_id', 'academic_year_id', 'id'])->on('ac_classes')->restrictOnDelete();
            $table->foreign(['tenant_id', 'organization_id', 'enrollment_id'])
                ->references(['tenant_id', 'organization_id', 'id'])->on('ac_enrollments')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE ac_class_members ADD CONSTRAINT ac_class_members_dates_check CHECK (end_date IS NULL OR end_date >= start_date)');
        DB::statement('CREATE UNIQUE INDEX ac_class_members_one_open_per_year_unique ON ac_class_members (enrollment_id, academic_year_id) WHERE end_date IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('ac_class_members');
        Schema::dropIfExists('ac_classes');
    }
};
