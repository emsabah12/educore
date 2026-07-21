<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('santris', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('class_id')->index()->nullable(); // Kunci logis, tanpa foreign key fisik di modul Core

            $table->string('nis', 50)->index();
            $table->string('name', 150);
            $table->enum('gender', ['L', 'P']);
            $table->string('birth_place', 100)->nullable();
            $table->date('birth_date')->nullable();
            $table->text('address')->nullable();
            $table->enum('status', ['active', 'inactive', 'graduated', 'mutated'])->default('active');

            $table->timestamps();
            $table->softDeletes();

            // Hubungan ke tenant aman karena tabel tenants berada di modul Core yang sama
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');

            // Composite unique memastikan NIS unik per lembaga/tenant
            $table->unique(['tenant_id', 'nis'], 'uq_tenant_nis');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('santris');
    }
};
