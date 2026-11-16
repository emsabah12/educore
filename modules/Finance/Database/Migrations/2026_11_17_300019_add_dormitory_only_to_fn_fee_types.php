<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biaya asrama (PRD-003 §20 FN-6, PRD-008 OD-DM-10): jenis biaya bertanda "Hanya penghuni asrama" hanya ditagihkan
 * kepada santri yang tinggal di asrama. Dipilih saat membuat, tidak bisa diubah (seperti sifat Bulanan/Sekali).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fn_fee_types', function (Blueprint $table) {
            $table->boolean('dormitory_only')->default(false)->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('fn_fee_types', function (Blueprint $table) {
            $table->dropColumn('dormitory_only');
        });
    }
};
