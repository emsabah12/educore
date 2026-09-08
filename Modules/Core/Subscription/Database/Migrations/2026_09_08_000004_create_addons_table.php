<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add-on yang bisa ditambahkan tenant di tengah masa langganan, di
 * luar fitur bawaan paket mereka. Untuk MVP satu add-on = satu fitur
 * (`feature_id` tunggal) — cukup untuk kasus "Custom Role" sebagai
 * add-on; bisa diperluas jadi pivot many-to-many kalau nanti ada
 * add-on yang membuka lebih dari satu fitur sekaligus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addons', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 100)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->uuid('feature_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('feature_id', 'fk_addons_feature')
                ->references('id')
                ->on('subscription_features')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addons');
    }
};
