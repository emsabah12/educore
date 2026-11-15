<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mutasi kamar (PRD-008 §8, §18 DM-4): biasa (diajukan → disetujui/ditolak/ditarik) dan darurat (langsung
 * dilaksanakan, lalu ditinjau). Asal & tujuan memakai FK gabungan ke dm_rooms sehingga pemilik & asrama yang
 * tersimpan selalu cocok dengan kamarnya. Satu pengajuan terbuka per santri dijaga partial unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dm_mutations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('organization_id');
            $table->uuid('dormitory_id');
            $table->uuid('from_room_id');
            $table->uuid('placement_id');
            $table->uuid('to_organization_id');
            $table->uuid('to_dormitory_id');
            $table->uuid('to_room_id');
            $table->uuid('membership_id');
            $table->string('type', 10);
            $table->string('status', 15);
            $table->string('emergency_category', 15)->nullable();
            $table->string('reason', 500);
            $table->uuid('requested_by')->nullable();
            $table->uuid('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->date('executed_on')->nullable();
            $table->uuid('new_placement_id')->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->string('review_note', 200)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'type']);
            $table->index(['tenant_id', 'organization_id']);
            $table->index(['tenant_id', 'to_organization_id']);
            $table->index('placement_id');

            $table->foreign(['tenant_id', 'organization_id', 'dormitory_id', 'from_room_id'])
                ->references(['tenant_id', 'organization_id', 'dormitory_id', 'id'])->on('dm_rooms')->restrictOnDelete();
            $table->foreign(['tenant_id', 'to_organization_id', 'to_dormitory_id', 'to_room_id'])
                ->references(['tenant_id', 'organization_id', 'dormitory_id', 'id'])->on('dm_rooms')->restrictOnDelete();
            $table->foreign(['tenant_id', 'membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->foreign('placement_id')->references('id')->on('dm_placements')->restrictOnDelete();
            $table->foreign('new_placement_id')->references('id')->on('dm_placements')->restrictOnDelete();
            $table->foreign('requested_by')->references('id')->on('memberships')->nullOnDelete();
            $table->foreign('decided_by')->references('id')->on('memberships')->nullOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('memberships')->nullOnDelete();
        });

        DB::statement("CREATE UNIQUE INDEX dm_mutations_one_open_per_person ON dm_mutations (tenant_id, membership_id) WHERE status = 'DIAJUKAN'");
        DB::statement(<<<'SQL'
            ALTER TABLE dm_mutations ADD CONSTRAINT dm_mutations_values_check CHECK (
                type IN ('BIASA', 'DARURAT')
                AND status IN ('DIAJUKAN', 'DILAKSANAKAN', 'DITOLAK', 'DIBATALKAN')
                AND (emergency_category IS NULL OR emergency_category IN ('KESEHATAN', 'KEAMANAN', 'KONFLIK', 'FASILITAS', 'LAINNYA'))
                AND to_room_id <> from_room_id
                AND length(btrim(reason)) > 0
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE dm_mutations ADD CONSTRAINT dm_mutations_state_check CHECK (
                (type = 'BIASA' AND emergency_category IS NULL AND reviewed_at IS NULL
                    OR type = 'DARURAT' AND emergency_category IS NOT NULL AND status = 'DILAKSANAKAN')
                AND (status <> 'DILAKSANAKAN' OR (executed_on IS NOT NULL AND new_placement_id IS NOT NULL))
                AND (status = 'DILAKSANAKAN' OR (executed_on IS NULL AND new_placement_id IS NULL))
                AND (status <> 'DITOLAK' OR decision_note IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('dm_mutations');
    }
};
