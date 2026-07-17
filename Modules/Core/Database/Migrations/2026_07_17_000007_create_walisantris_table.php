<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('walisantris', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Jembatan Konteks Multi-Tenant & Identitas
            $table->uuid('tenant_id')->index();
            $table->uuid('membership_id')->unique()->index();

            // Atribut Bisnis Spesifik Wali Santri
            $table->string('no_hp', 25)->nullable();
            $table->text('alamat_domisili')->nullable();

            $table->timestamps();

            // Foreign Key Constraints
            $table->foreign('membership_id')
                ->references('id')
                ->on('memberships')
                ->onDelete('cascade');

            $table->softDeletes()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('walisantris');
    }
};
