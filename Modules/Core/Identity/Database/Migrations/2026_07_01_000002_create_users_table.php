<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('person_id')->unique();

            $table->string('email', 255)->unique();
            $table->timestamp('email_verified_at')->nullable();

            $table->string('password');

            $table->string('status', 20)
                ->default('ACTIVE')
                ->index();

            $table->boolean('is_superadmin')
                ->default(false);

            $table->rememberToken();
            $table->timestamps();

            $table->foreign('person_id')
                ->references('id')
                ->on('persons')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
