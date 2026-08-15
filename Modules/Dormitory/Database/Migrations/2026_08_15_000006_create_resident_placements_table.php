<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * PostgreSQL requires the referenced composite FK target to have an
         * exact unique/primary constraint. These supporting constraints keep
         * Bed/Locker ownership qualified by their canonical Room and Tenant.
         */
        Schema::table('beds', function (Blueprint $table): void {
            $table->unique(
                ['id', 'room_id', 'tenant_id'],
                'uq_beds_id_room_tenant',
            );
        });

        Schema::table('lockers', function (Blueprint $table): void {
            $table->unique(
                ['id', 'room_id', 'tenant_id'],
                'uq_lockers_id_room_tenant',
            );
        });

        Schema::create('resident_placements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('membership_id');
            $table->uuid('room_id');
            $table->uuid('bed_id')->nullable();
            $table->uuid('locker_id')->nullable();

            $table->string('resident_category', 32);
            $table->string('status', 20);

            $table->timestampTz('planned_at');
            $table->timestampTz('checked_in_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();

            $table->text('end_reason')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->timestamps();

            $table->unique(
                ['id', 'tenant_id'],
                'uq_resident_placements_id_tenant',
            );

            $table->index(
                ['tenant_id', 'status'],
                'idx_resident_placements_tenant_status',
            );

            $table->index(
                ['membership_id', 'status'],
                'idx_resident_placements_membership_status',
            );

            $table->index(
                ['room_id', 'status'],
                'idx_resident_placements_room_status',
            );

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->restrictOnDelete();

            $table->foreign(
                ['membership_id', 'tenant_id'],
                'fk_resident_placements_membership_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('memberships')
                ->restrictOnDelete();

            $table->foreign(
                ['room_id', 'tenant_id'],
                'fk_resident_placements_room_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('rooms')
                ->restrictOnDelete();

            /*
             * MATCH SIMPLE semantics allow NULL bed_id/locker_id for PLANNED
             * placements while still proving nested Room + Tenant ownership
             * whenever a resource is allocated.
             */
            $table->foreign(
                ['bed_id', 'room_id', 'tenant_id'],
                'fk_resident_placements_bed_room_tenant',
            )
                ->references(['id', 'room_id', 'tenant_id'])
                ->on('beds')
                ->restrictOnDelete();

            $table->foreign(
                ['locker_id', 'room_id', 'tenant_id'],
                'fk_resident_placements_locker_room_tenant',
            )
                ->references(['id', 'room_id', 'tenant_id'])
                ->on('lockers')
                ->restrictOnDelete();
        });

        /*
         * Historical PLANNED/ENDED/CANCELLED rows are preserved, while the
         * database remains the final race-condition guard for ACTIVE state.
         */
        DB::statement(
            <<<'SQL'
            CREATE UNIQUE INDEX uq_resident_placements_active_membership
            ON resident_placements (tenant_id, membership_id)
            WHERE status = 'ACTIVE'
            SQL,
        );

        DB::statement(
            <<<'SQL'
            CREATE UNIQUE INDEX uq_resident_placements_active_bed
            ON resident_placements (tenant_id, bed_id)
            WHERE status = 'ACTIVE' AND bed_id IS NOT NULL
            SQL,
        );

        DB::statement(
            <<<'SQL'
            CREATE UNIQUE INDEX uq_resident_placements_active_locker
            ON resident_placements (tenant_id, locker_id)
            WHERE status = 'ACTIVE' AND locker_id IS NOT NULL
            SQL,
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE resident_placements
            ADD CONSTRAINT chk_resident_placements_category
            CHECK (resident_category IN ('REGULAR_RESIDENT', 'SUPERVISOR_RESIDENT'))
            SQL,
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE resident_placements
            ADD CONSTRAINT chk_resident_placements_status
            CHECK (status IN ('PLANNED', 'ACTIVE', 'ENDED', 'CANCELLED'))
            SQL,
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE resident_placements
            ADD CONSTRAINT chk_resident_placements_lifecycle
            CHECK (
                (status = 'PLANNED'
                    AND checked_in_at IS NULL
                    AND ended_at IS NULL
                    AND cancelled_at IS NULL)
                OR
                (status = 'ACTIVE'
                    AND checked_in_at IS NOT NULL
                    AND ended_at IS NULL
                    AND cancelled_at IS NULL)
                OR
                (status = 'ENDED'
                    AND checked_in_at IS NOT NULL
                    AND ended_at IS NOT NULL
                    AND cancelled_at IS NULL)
                OR
                (status = 'CANCELLED'
                    AND checked_in_at IS NULL
                    AND ended_at IS NULL
                    AND cancelled_at IS NOT NULL)
            )
            SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_placements');

        Schema::table('lockers', function (Blueprint $table): void {
            $table->dropUnique('uq_lockers_id_room_tenant');
        });

        Schema::table('beds', function (Blueprint $table): void {
            $table->dropUnique('uq_beds_id_room_tenant');
        });
    }
};
