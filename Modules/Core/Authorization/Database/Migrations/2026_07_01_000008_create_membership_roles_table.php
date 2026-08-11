<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_roles', function (Blueprint $table): void {
            $table->uuid('membership_id');
            $table->uuid('role_id');

            $table->primary([
                'membership_id',
                'role_id',
            ]);

            /*
             * Composite PK sudah melayani lookup berdasarkan
             * membership_id.
             *
             * Index role_id diperlukan untuk reverse lookup
             * Role → MembershipRole dan FK maintenance.
             */
            $table->index('role_id');

            $table->foreign('membership_id')
                ->references('id')
                ->on('memberships')
                ->cascadeOnDelete();

            $table->foreign('role_id')
                ->references('id')
                ->on('roles')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_roles');
    }
};
