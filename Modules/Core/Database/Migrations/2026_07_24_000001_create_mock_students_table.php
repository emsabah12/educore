<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the mock_students table.
     */
    public function up(): void
    {
        if (Schema::hasTable('mock_students')) {
            return;
        }

        Schema::create('mock_students', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('nisn', 20);
            $table->string('status', 50);

            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'status']);
        });
    }

    /**
     * Drop the mock_students table.
     */
    public function down(): void
    {
        Schema::dropIfExists('mock_students');
    }
};
