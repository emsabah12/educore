<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR-003 §7.3 — "Ordered selection stages for one Vacancy."
 *
 * "Micro-teaching is simply an optional stage; no special teacher-only
 * workflow is hardcoded into the engine." Ini prinsip desain penting:
 * tabel ini generik untuk SEMUA jenis tahap seleksi (screening
 * administrasi, tes, wawancara, micro-teaching, dst) — bukan tabel
 * khusus per jenis tahap. Kalau suatu saat ada jenis tahap baru,
 * cukup baris baru dengan `code` baru, tidak perlu migration baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_vacancy_stages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('vacancy_id');
            $table->string('code', 50);
            $table->string('name', 150);
            $table->unsignedInteger('sequence');
            $table->boolean('is_required')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['vacancy_id', 'code'],
                'uq_recruitment_vacancy_stages_vacancy_code',
            );
            $table->unique(
                ['vacancy_id', 'sequence'],
                'uq_recruitment_vacancy_stages_vacancy_sequence',
            );

            // Supporting key untuk composite FK dari
            // recruitment_application_stages di masa depan, kalau perlu
            // dijamin secara database bahwa sebuah stage snapshot
            // menunjuk ke vacancy_stage yang benar-benar milik Vacancy
            // yang sama dengan Application-nya.
            $table->unique(
                ['id', 'vacancy_id', 'tenant_id'],
                'uq_recruitment_vacancy_stages_id_vacancy_tenant',
            );

            $table->index(
                ['vacancy_id', 'sequence'],
                'idx_recruitment_vacancy_stages_vacancy_sequence_lookup',
            );

            $table->foreign(
                ['vacancy_id', 'tenant_id'],
                'fk_recruitment_vacancy_stages_vacancy_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('recruitment_vacancies')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_vacancy_stages');
    }
};
