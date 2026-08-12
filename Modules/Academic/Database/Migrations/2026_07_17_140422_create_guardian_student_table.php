<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guardian_student', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('guardian_id')->index();
            $table->uuid('student_id')->index();
            $table->string('relationship_type', 50);
            $table->timestamps();

            $table->unique([
                'tenant_id',
                'guardian_id',
                'student_id',
            ]);

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->restrictOnDelete();

            $table->foreign('guardian_id')
                ->references('id')
                ->on('guardians')
                ->cascadeOnDelete();

            $table->foreign('student_id')
                ->references('id')
                ->on('students')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guardian_student');
    }
};
