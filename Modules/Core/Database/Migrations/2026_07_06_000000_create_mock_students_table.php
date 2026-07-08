<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan migrasi tabel.
     */
    public function up(): void
    {
        Schema::create('mock_students', function (Blueprint $table) {
            // Menggunakan macro kustom kita untuk Primary Key UUID v7
            $table->uuid7('id'); 
            
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });
    }

    /**
     * Batalkan migrasi tabel.
     */
    public function down(): void
    {
        Schema::dropIfExists('mock_students');
    }
};