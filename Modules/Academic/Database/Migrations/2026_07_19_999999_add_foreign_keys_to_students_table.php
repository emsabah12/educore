<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Pastikan kedua tabel sudah terbentuk sebelum melakukan alter table
        if (Schema::hasTable('students') && Schema::hasTable('academic_classes')) {
            Schema::table('students', function (Blueprint $table) {
                $table->foreign('class_id')
                    ->references('id')
                    ->on('academic_classes')
                    ->onDelete('restrict');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('students')) {
            Schema::table('students', function (Blueprint $table) {
                $table->dropForeign(['class_id']);
            });
        }
    }
};
