<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak audit aksi penting (PRD-000 §5, §10 F4, OD-15).
 *
 * Append-only: database menolak UPDATE, DELETE, dan TRUNCATE. Satu-satunya pengecualian
 * adalah penghapusan log lama oleh `educore:prune-audit` (masa simpan, OD-16), yang
 * menyalakan `SET LOCAL educore.audit_prune = 'on'` di dalam transaksinya sendiri; itu pun
 * hanya untuk baris berumur lebih dari 29 hari (masa simpan minimal 30 hari, selisih zona waktu aman).
 *
 * Batasannya: ini melindungi dari bug aplikasi, bukan dari orang yang memegang kredensial
 * database pemilik tabel (ia bisa mematikan trigger). Perlindungan penuh butuh role database
 * terpisah sebagai pemilik tabel (lihat docs/operations/BACKUP-RESTORE.md).
 *
 * tenant_id kosong = kejadian di luar yayasan (login, keamanan akun).
 * actor_user_id kosong = dilakukan sistem atau tamu (mis. login gagal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->uuid('actor_user_id')->nullable();
            $table->uuid('actor_membership_id')->nullable();
            $table->uuid('organization_id')->nullable();
            $table->string('action', 100);
            $table->string('subject_type', 50)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->jsonb('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at');

            // Sengaja tanpa foreign key ke users/organizations: log harus tetap utuh
            // apa pun yang terjadi pada data yang dirujuknya.
            $table->index(['tenant_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('actor_user_id');
            $table->index('created_at');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE'
                    AND current_setting('educore.audit_prune', true) = 'on'
                    AND OLD.created_at < now() - interval '29 days' THEN
                    RETURN OLD;
                END IF;
                RAISE EXCEPTION 'audit_logs hanya boleh ditambah (append-only); % ditolak.', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_logs_no_update_delete
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_append_only();

            CREATE TRIGGER audit_logs_no_truncate
                BEFORE TRUNCATE ON audit_logs
                FOR EACH STATEMENT EXECUTE FUNCTION audit_logs_append_only();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_logs_append_only()');
    }
};
