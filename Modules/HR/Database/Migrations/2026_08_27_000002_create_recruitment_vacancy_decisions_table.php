<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-003 §7.2 — "Explicit business approval/rejection evidence."
 *
 * "No decision is inferred only from generic audit logs." Ini tabel
 * kecil tapi penting: keputusan approve/reject Vacancy adalah fakta
 * bisnis yang harus punya baris datanya sendiri (siapa, kapan, kenapa)
 * — bukan sekadar disimpulkan dari log audit generik yang sifatnya
 * best-effort dan bisa hilang (lihat catatan fail-open audit trail di
 * gap analysis awal kita).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_vacancy_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('vacancy_id');
            $table->string('decision', 16);
            $table->uuid('decided_by_membership_id');
            $table->text('reason')->nullable();
            $table->timestampTz('decided_at');
            $table->timestamps();

            $table->index(
                ['vacancy_id', 'decided_at'],
                'idx_recruitment_vacancy_decisions_vacancy_decided',
            );

            $table->foreign(
                ['vacancy_id', 'tenant_id'],
                'fk_recruitment_vacancy_decisions_vacancy_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('recruitment_vacancies')
                ->restrictOnDelete();

            $table->foreign(
                'decided_by_membership_id',
                'fk_recruitment_vacancy_decisions_decided_by_membership',
            )
                ->references('id')
                ->on('memberships')
                ->restrictOnDelete();
        });

        DB::statement(
            <<<'SQL'
                ALTER TABLE recruitment_vacancy_decisions
                ADD CONSTRAINT chk_recruitment_vacancy_decisions_decision
                CHECK (decision IN ('APPROVED', 'REJECTED'))
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_vacancy_decisions');
    }
};
