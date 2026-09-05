<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EXTEND — integrity support only. Menambahkan supporting key
 * (id, tenant_id) di recruitment_application_stages supaya
 * recruitment_evaluations (§7.8) bisa memakai composite FK tenant-safe,
 * mengikuti pola yang sama seperti seluruh tabel Recruitment lainnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_application_stages', function (Blueprint $table): void {
            $table->unique(
                ['id', 'tenant_id'],
                'uq_recruitment_application_stages_id_tenant',
            );
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_application_stages', function (Blueprint $table): void {
            $table->dropUnique('uq_recruitment_application_stages_id_tenant');
        });
    }
};
