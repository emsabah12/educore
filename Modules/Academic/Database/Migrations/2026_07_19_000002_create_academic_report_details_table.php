<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_report_details', function (Blueprint $bluePrint) {
            $bluePrint->uuid('id')->primary();
            $bluePrint->uuid('tenant_id')->index();
            $bluePrint->uuid('academic_report_card_id')->index();
            $bluePrint->uuid('academic_subject_id')->index();

            // Aggregated Scores
            $bluePrint->decimal('final_score', 5, 2); // Nilai akhir hasil kalkulasi bobot
            $bluePrint->string('letter_grade', 2)->nullable(); // A, B, C, D, E
            $bluePrint->text('predicate_notes')->nullable(); // Deskripsi capaian kompetensi

            $bluePrint->timestamps();

            // Relations & Multi-tenant Integrity
            $bluePrint->foreign('academic_report_card_id')
                ->references('id')
                ->on('academic_report_cards')
                ->onDelete('cascade');

            $bluePrint->unique(['tenant_id', 'academic_report_card_id', 'academic_subject_id'], 'uq_tenant_report_subject');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_report_details');
    }
};
