<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-003 §7.9 — "Explicit final hiring approval evidence."
 *
 * "Only latest valid approved decision allows hire conversion. Decision
 * history is never overwritten." — tabel ini APPEND-ONLY (tidak ada
 * UPDATE/DELETE dari aplikasi), persis pola yang sama seperti
 * recruitment_vacancy_decisions di Fase A: keputusan bisnis final harus
 * punya bukti baris sendiri, bukan sekadar disimpulkan dari kolom
 * `status` di recruitment_applications.
 *
 * Baris di sini ditulis dari DUA aksi lifecycle Application (§8.2):
 * reject() dan approveForHiring() — keduanya "keputusan hiring final",
 * bedanya cuma hasilnya. withdraw() TIDAK menulis baris di sini karena
 * itu keputusan Candidate sendiri, bukan keputusan evaluator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_hiring_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('application_id');
            $table->string('decision', 16);
            $table->uuid('decided_by_membership_id');
            $table->text('reason')->nullable();
            $table->timestampTz('decided_at');
            $table->timestamps();

            $table->index(
                ['application_id', 'decided_at'],
                'idx_recruitment_hiring_decisions_application_decided',
            );

            $table->foreign(
                ['application_id', 'tenant_id'],
                'fk_recruitment_hiring_decisions_application_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('recruitment_applications')
                ->restrictOnDelete();

            $table->foreign(
                'decided_by_membership_id',
                'fk_recruitment_hiring_decisions_decided_by_membership',
            )
                ->references('id')
                ->on('memberships')
                ->restrictOnDelete();
        });

        DB::statement(
            <<<'SQL'
                ALTER TABLE recruitment_hiring_decisions
                ADD CONSTRAINT chk_recruitment_hiring_decisions_decision
                CHECK (decision IN ('APPROVED', 'REJECTED'))
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_hiring_decisions');
    }
};
