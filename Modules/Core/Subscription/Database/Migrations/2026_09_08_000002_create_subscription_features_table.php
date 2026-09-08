<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Katalog fitur bernama (feature flag) — GLOBAL. Ini unit gating yang
 * SEBENARNYA dipakai paket/add-on ("Custom Role" adalah SATU fitur,
 * bukan kumpulan permission yang dicek satu-satu — lihat diskusi PRD:
 * seluruh role kustom tenant dilock BERSAMAAN saat fitur ini tidak
 * lagi dimiliki tenant).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_features', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 100)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_features');
    }
};
