<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table): void {
            /*
             * Canonical membership identity.
             *
             * UUIDv7 generation dilakukan di application/model layer.
             */
            $table->uuid('id')->primary();

            /*
             * Membership dimiliki oleh Person,
             * bukan oleh authentication account (User).
             */
            $table->uuid('person_id');

            /*
             * Tenant tempat Person tersebut menjadi member.
             */
            $table->uuid('tenant_id');

            /*
             * Membership lifecycle.
             *
             * Authorization role TIDAK disimpan di sini.
             */
            $table->string('status', 20)
                ->default('ACTIVE');

            $table->timestamps();

            /*
             * Satu Person hanya boleh mempunyai satu Membership
             * dalam Tenant yang sama.
             */
            $table->unique([
                'person_id',
                'tenant_id',
            ]);

            /*
             * Composite unique index di atas sudah dapat melayani
             * lookup berbasis person_id.
             *
             * Tenant membutuhkan index tersendiri karena tenant_id
             * adalah kolom kedua composite index.
             */
            $table->index('tenant_id');

            /*
             * Membership tidak boleh hilang secara implisit
             * ketika Person atau Tenant di-hard-delete.
             */
            $table->foreign('person_id')
                ->references('id')
                ->on('persons')
                ->restrictOnDelete();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
