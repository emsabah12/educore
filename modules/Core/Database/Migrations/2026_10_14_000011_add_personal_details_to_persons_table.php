<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data pribadi minimal milik Person, dibutuhkan modul HR (PRD-001 §4.5, OD-HR-01).
 *
 * Disimpan di Person (bukan di HR) karena data ini milik orangnya; modul Academic kelak
 * memakai kolom yang sama. Semua opsional. NIK sengaja TIDAK disimpan di sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            $table->string('birth_place', 100)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('address', 500)->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE persons ADD CONSTRAINT persons_phone_format_check
                CHECK (phone IS NULL OR phone ~ '^\+?[0-9]{8,19}$')
        SQL);
        DB::statement("ALTER TABLE persons ADD CONSTRAINT persons_birth_place_not_blank_check CHECK (birth_place IS NULL OR btrim(birth_place) <> '')");
        DB::statement("ALTER TABLE persons ADD CONSTRAINT persons_address_not_blank_check CHECK (address IS NULL OR btrim(address) <> '')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE persons DROP CONSTRAINT IF EXISTS persons_phone_format_check');
        DB::statement('ALTER TABLE persons DROP CONSTRAINT IF EXISTS persons_birth_place_not_blank_check');
        DB::statement('ALTER TABLE persons DROP CONSTRAINT IF EXISTS persons_address_not_blank_check');

        Schema::table('persons', function (Blueprint $table) {
            $table->dropColumn(['birth_place', 'birth_date', 'phone', 'address']);
        });
    }
};
