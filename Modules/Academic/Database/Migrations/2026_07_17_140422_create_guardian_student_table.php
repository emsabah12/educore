<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membuat tabel pivot relasi guardian dan student.
     */
    public function up(): void
    {
        Schema::create('guardian_student', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            /**
             * Tenant boundary.
             *
             * Semua relasi guardian-student harus selalu
             * berada dalam satu tenant yang sama.
             */
            $table->uuid('tenant_id')->index();

            $table->uuid('guardian_id')->index();
            $table->uuid('student_id')->index();

            /**
             * Jenis hubungan guardian dengan student.
             *
             * Contoh:
             * - FATHER
             * - MOTHER
             * - GUARDIAN
             */
            $table
                ->string('relationship_type', 50)
                ->default('GUARDIAN');

            $table->timestamps();

            /**
             * Satu guardian hanya boleh terhubung
             * satu kali dengan student yang sama
             * dalam tenant yang sama.
             */
            $table->unique([
                'tenant_id',
                'guardian_id',
                'student_id',
            ]);

            /**
             * Tenant harus valid.
             */
            $table
                ->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            /**
             * Guardian harus berasal dari tenant
             * yang sama secara logis.
             */
            $table
                ->foreign('guardian_id')
                ->references('id')
                ->on('guardians')
                ->cascadeOnDelete();

            /**
             * Student harus berasal dari tenant
             * yang sama secara logis.
             */
            $table
                ->foreign('student_id')
                ->references('id')
                ->on('students')
                ->cascadeOnDelete();
        });
    }

    /**
     * Menghapus tabel pivot.
     */
    public function down(): void
    {
        Schema::dropIfExists('guardian_student');
    }
};
