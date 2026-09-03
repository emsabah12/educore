<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employment_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 50);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // OD-HR-DATA-002: katalog Employment Type harus tenant-scoped,
            // bukan hardcoded enum, sehingga tiap tenant boleh punya
            // "kode" sendiri tanpa bentrok dengan tenant lain.
            $table->unique([
                'tenant_id',
                'code',
            ]);

            // Composite unique (id, tenant_id) ini BUKAN untuk mencegah
            // duplikasi baris biasa (id sudah PK), tapi supaya tabel lain
            // (mis. employments) bisa membuat composite foreign key
            // (employment_type_id, tenant_id) yang menjamin baris child
            // selalu merujuk ke katalog milik tenant yang sama. Ini
            // mencegah "cross-tenant reference" di level database, bukan
            // hanya di level aplikasi.
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
        Schema::dropIfExists('employment_types');
    }
};
