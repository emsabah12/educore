<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Closure table: menyimpan SEMUA pasangan induk → turunan beserta jaraknya.
 *
 * Contoh untuk Ponpes → Unit 1 → MA Unit 1:
 *   (Ponpes, Ponpes, 0)  (Unit 1, Unit 1, 0)  (MA, MA, 0)
 *   (Ponpes, Unit 1, 1)  (Unit 1, MA, 1)
 *   (Ponpes, MA, 2)
 *
 * Dengan begitu "semua turunan node X" atau "semua induk node X" cukup satu
 * query sederhana, tanpa query rekursif di setiap request (PRD-000 §5, §7.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_closure', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->uuid('ancestor_id');
            $table->uuid('descendant_id');
            $table->unsignedSmallInteger('depth');

            $table->primary(['ancestor_id', 'descendant_id']);
            $table->index(['descendant_id', 'depth']);
            $table->index(['tenant_id', 'ancestor_id']);

            // Kedua ujung wajib berada di tenant yang sama dengan baris closure ini.
            $table->foreign(['tenant_id', 'ancestor_id'])
                ->references(['tenant_id', 'id'])
                ->on('organizations')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'descendant_id'])
                ->references(['tenant_id', 'id'])
                ->on('organizations')
                ->cascadeOnDelete();
        });

        // Baris diri-sendiri (depth 0) hanya boleh untuk ancestor = descendant, dan sebaliknya.
        DB::statement('ALTER TABLE organization_closure ADD CONSTRAINT organization_closure_self_depth_check CHECK ((ancestor_id = descendant_id) = (depth = 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_closure');
    }
};
