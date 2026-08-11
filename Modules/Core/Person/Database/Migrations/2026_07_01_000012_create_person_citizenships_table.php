<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_citizenships', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('person_id');

            $table->char('country_code', 2);

            $table->boolean('is_primary')
                ->default(false);

            /*
             * Nullable agar historical/imported data
             * yang tidak lengkap tetap representable.
             */
            $table->date('acquired_at')->nullable();
            $table->date('ended_at')->nullable();

            $table->timestamps();

            $table->unique([
                'person_id',
                'country_code',
            ]);

            $table->foreign('person_id')
                ->references('id')
                ->on('persons')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_citizenships');
    }
};
