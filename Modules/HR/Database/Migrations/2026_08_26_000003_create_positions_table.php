<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 50);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // INV-HR-003 (HR-002 §7): "Position is not authorization."
            // Tabel ini SENGAJA tidak memiliki kolom role_id, permission_id,
            // organization_id, organization_unit_id, subject_id, atau
            // class_id. Position murni katalog jabatan HR (mis. "Guru
            // Matematika", "Kepala Tata Usaha") — bukan sumber hak akses.
            // Otorisasi tetap sepenuhnya ditentukan oleh Core RBAC
            // (role & permission), terpisah total dari tabel ini.
            // Kalau di masa depan ada kebutuhan "jabatan X otomatis dapat
            // role Y", itu adalah proses/service terpisah yang secara
            // eksplisit menjembatani dua domain — bukan menambah kolom di
            // sini.
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
        Schema::dropIfExists('positions');
    }
};
