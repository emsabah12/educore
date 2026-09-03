<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employment_classifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 50);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Pola constraint identik dengan employment_types: katalog ini
            // sengaja dipisah dari employment_types (bukan digabung jadi
            // satu tabel "categories" generik) karena HR-002 §3
            // (OD-HR-DATA-002) mengunci keduanya sebagai domain concept
            // yang berbeda: Employment Type = model hubungan kerja
            // (TETAP/KONTRAK), Employment Classification = klasifikasi
            // institusional (GTY/GTT/PTY/PTT). Memisahkan tabel membuat
            // aturan unik & siklus hidup masing-masing independen.
            $table->unique([
                'tenant_id',
                'code',
            ]);

            $table->unique([
                'id',
                'tenant_id',
            ]);

            $table->index([
                'tenant_id',
                'is_active',
            ]);

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_classifications');
    }
};
