<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Katalog paket subscription — GLOBAL, dikelola superadmin, dipakai
 * lintas seluruh tenant (mirip `roles`/`permissions`: tidak ada
 * `tenant_id` di sini).
 *
 * `grace_period_days`: berapa lama add-on/paket yang dicabut tetap
 * berstatus LOCKED_READONLY sebelum berpindah ke LOCKED_HIDDEN.
 * Default 30 hari, bisa diatur per paket oleh superadmin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('grace_period_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
