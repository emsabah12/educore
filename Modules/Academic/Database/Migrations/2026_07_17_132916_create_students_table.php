<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('membership_id')->unique();
            $table->uuid('class_id')->nullable()->index();

            $table->string('nis', 50)->nullable();
            $table->string('nisn', 20)->nullable()->index();
            $table->enum(
                'status',
                ['active', 'inactive', 'graduated', 'mutated'],
            )->default('active')->index();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['tenant_id', 'nis'],
                'uq_tenant_nis',
            );

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->restrictOnDelete();

            $table->foreign('membership_id')
                ->references('id')
                ->on('memberships')
                ->restrictOnDelete();

            $table->foreign('class_id')
                ->references('id')
                ->on('academic_classes')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
