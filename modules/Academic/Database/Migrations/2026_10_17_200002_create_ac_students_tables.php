<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Profil siswa & relasi orang tua/wali (PRD-002 §4.2, §4.5).
 *
 * - Satu profil siswa per membership (orang × yayasan). Isinya hanya NISN; nama & data pribadi
 *   ada di Person, status siswa ada di pendaftaran per lembaga (ac_enrollments).
 * - Orang tua/wali = Person + Membership biasa (tanpa akun login), dihubungkan lewat tabel relasi.
 *   Maksimal 4 per siswa dan "bukan dirinya sendiri" dicek service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ac_students', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('membership_id');
            $table->string('nisn', 10)->nullable();
            $table->timestamps();

            $table->unique('membership_id');
            $table->unique(['tenant_id', 'nisn']);
            $table->unique(['tenant_id', 'id']);

            $table->foreign(['tenant_id', 'membership_id'])
                ->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE ac_students ADD CONSTRAINT ac_students_nisn_format_check CHECK (nisn IS NULL OR nisn ~ '^[0-9]{10}$')");

        Schema::create('ac_student_guardians', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('student_id');
            $table->uuid('guardian_membership_id');
            $table->string('relation', 10);
            $table->boolean('is_primary_contact')->default(false);
            $table->timestamps();

            $table->unique(['student_id', 'guardian_membership_id']);
            $table->index(['tenant_id', 'guardian_membership_id']);

            $table->foreign(['tenant_id', 'student_id'])
                ->references(['tenant_id', 'id'])->on('ac_students')->restrictOnDelete();
            $table->foreign(['tenant_id', 'guardian_membership_id'])
                ->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE ac_student_guardians ADD CONSTRAINT ac_student_guardians_relation_check CHECK (relation IN ('AYAH', 'IBU', 'WALI', 'LAINNYA'))");
        DB::statement('CREATE UNIQUE INDEX ac_student_guardians_one_primary_unique ON ac_student_guardians (student_id) WHERE is_primary_contact');
    }

    public function down(): void
    {
        Schema::dropIfExists('ac_student_guardians');
        Schema::dropIfExists('ac_students');
    }
};
