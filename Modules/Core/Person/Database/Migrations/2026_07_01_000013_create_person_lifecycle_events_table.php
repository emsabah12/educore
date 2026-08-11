<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'person_lifecycle_events',
            function (Blueprint $table): void {
                /*
                 * Immutable lifecycle event identity.
                 *
                 * UUIDv7 generation dilakukan application layer.
                 */
                $table->uuid('id')->primary();

                /*
                 * Person yang mengalami lifecycle transition.
                 */
                $table->uuid('person_id');

                /*
                 * CREATED / ACTIVATED / DEACTIVATED /
                 * ARCHIVED / DECEASED.
                 *
                 * Domain enum, bukan database ENUM.
                 */
                $table->string('type', 32);

                /*
                 * Waktu lifecycle transition secara domain.
                 */
                $table->timestampTz('occurred_at');

                /*
                 * Authenticated account yang melakukan perubahan.
                 *
                 * Nullable untuk system/import/bootstrap operation.
                 */
                $table->uuid('actor_user_id')->nullable();

                /*
                 * Optional business explanation.
                 */
                $table->text('reason')->nullable();

                /*
                 * Persistence timestamp.
                 *
                 * Tidak ada updated_at karena lifecycle event
                 * bersifat immutable.
                 */
                $table->timestampTz('created_at')->useCurrent();

                /*
                 * Primary access pattern:
                 * timeline lifecycle satu Person.
                 */
                $table->index(
                    [
                        'person_id',
                        'occurred_at',
                    ],
                    'person_lifecycle_events_person_occurred_idx',
                );

                /*
                 * Mendukung FK maintenance / optional reverse lookup
                 * berdasarkan actor tanpa membuat speculative
                 * chronological actor index.
                 */
                $table->index(
                    'actor_user_id',
                    'person_lifecycle_events_actor_user_idx',
                );

                $table->foreign('person_id')
                    ->references('id')
                    ->on('persons')
                    ->cascadeOnDelete();

                $table->foreign('actor_user_id')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            },
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('person_lifecycle_events');
    }
};
