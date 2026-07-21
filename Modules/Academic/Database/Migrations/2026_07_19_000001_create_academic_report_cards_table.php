<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_report_cards', function (Blueprint $bluePrint) {
            $bluePrint->uuid('id')->primary();
            $bluePrint->uuid('tenant_id')->index();
            $bluePrint->uuid('academic_period_id')->index();
            $bluePrint->uuid('santri_id')->index();
            $bluePrint->uuid('academic_class_id')->index();

            // Attendance & Notes
            $bluePrint->integer('attendance_sick')->default(0);
            $bluePrint->integer('attendance_permission')->default(0);
            $bluePrint->integer('attendance_absent')->default(0);
            $bluePrint->text('teacher_notes')->nullable();

            // Status & Lock Mechanism
            $bluePrint->string('status')->default('draft'); // draft, locked, published
            $bluePrint->uuid('locked_by')->nullable();
            $bluePrint->timestamp('locked_at')->nullable();

            $bluePrint->timestamps();

            // Foreign Key Constraints (Assuming users table holds staff info)
            $bluePrint->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');

            // Composite Index untuk optimasi pencarian rapor per santri per periode
            $bluePrint->unique(['tenant_id', 'academic_period_id', 'santri_id'], 'uq_tenant_period_santri');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_report_cards');
    }
};
