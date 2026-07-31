<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_lifecycle_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('person_id');

            $table->string('type');

            $table->timestampTz('occurred_at');

            $table->uuid('actor_id')->nullable();

            $table->text('reason')->nullable();

            $table->timestamps();

            $table->index(
                ['person_id', 'occurred_at'],
                'person_lifecycle_events_person_occurred_idx',
            );

            $table->index(
                ['actor_id', 'occurred_at'],
                'person_lifecycle_events_actor_occurred_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_lifecycle_events');
    }
};
