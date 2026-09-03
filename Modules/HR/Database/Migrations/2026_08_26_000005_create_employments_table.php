<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-002 §5.5 + §6.1 — inti dari Workforce Foundation.
 *
 * Tabel ini merepresentasikan satu "episode" hubungan kerja seorang
 * Employee (OD-HR-DATA-001: satu Employee boleh punya banyak Employment
 * sepanjang waktu, tapi maksimal satu yang berstatus ACTIVE).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('employee_id');
            $table->uuid('employment_type_id')->nullable();
            $table->uuid('employment_classification_id')->nullable();
            $table->string('status', 20)->default('PLANNED');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['id', 'tenant_id'],
                'uq_employments_id_tenant',
            );

            $table->index(
                ['tenant_id', 'status'],
                'idx_employments_tenant_status',
            );
            $table->index(
                ['employee_id', 'status'],
                'idx_employments_employee_status',
            );
            $table->index(
                ['tenant_id', 'employment_type_id', 'status'],
                'idx_employments_type_status',
            );
            $table->index(
                ['tenant_id', 'employment_classification_id', 'status'],
                'idx_employments_classification_status',
            );

            // Tenant-safe composite FK: baris employments TIDAK BISA
            // menunjuk ke Employee/EmploymentType/EmploymentClassification
            // milik tenant lain, walaupun UUID-nya valid. Ini penegakan
            // isolasi tenant di level database, bukan cuma aplikasi.
            $table->foreign(
                ['employee_id', 'tenant_id'],
                'fk_employments_employee_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('employees')
                ->restrictOnDelete();

            $table->foreign(
                ['employment_type_id', 'tenant_id'],
                'fk_employments_type_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('employment_types')
                ->restrictOnDelete();

            $table->foreign(
                ['employment_classification_id', 'tenant_id'],
                'fk_employments_classification_tenant',
            )
                ->references(['id', 'tenant_id'])
                ->on('employment_classifications')
                ->restrictOnDelete();
        });

        // ---------------------------------------------------------------
        // INV-HR-002 — Maximum one ACTIVE Employment per Employee.
        //
        // Application service NANTI akan mengecek ini dulu di dalam
        // transaction (lihat HR-002 §9.1 langkah 9), tapi itu saja tidak
        // cukup untuk race condition: dua request "activate employment"
        // yang datang nyaris bersamaan bisa sama-sama lolos pengecekan
        // aplikasi sebelum salah satunya sempat commit. Partial unique
        // index ini adalah GARDA TERAKHIR di level database yang membuat
        // race condition seperti itu mustahil menghasilkan dua baris
        // ACTIVE sekaligus — percobaan kedua akan gagal dengan
        // constraint violation, bukan silent bug data.
        //
        // "Partial" artinya index ini hanya berlaku untuk baris dengan
        // status = 'ACTIVE'; Employee boleh punya banyak baris ENDED/
        // CANCELLED/PLANNED tanpa terbentur constraint ini.
        // ---------------------------------------------------------------
        DB::statement(
            <<<'SQL'
                CREATE UNIQUE INDEX uq_employments_active_employee
                ON employments (tenant_id, employee_id)
                WHERE status = 'ACTIVE'
                SQL,
        );

        // Lifecycle rules dari HR-002 §5.5 ditegakkan sebagai CHECK
        // constraint supaya baris yang melanggar aturan status/tanggal
        // tidak mungkin tersimpan sama sekali, apa pun jalur kodenya
        // (termasuk kalau ada bug di service layer nanti).
        DB::statement(
            <<<'SQL'
                ALTER TABLE employments
                ADD CONSTRAINT chk_employments_status
                CHECK (status IN ('PLANNED', 'ACTIVE', 'ENDED', 'CANCELLED'))
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE employments
                ADD CONSTRAINT chk_employments_end_date_matches_status
                CHECK (
                    (status = 'ENDED' AND end_date IS NOT NULL)
                    OR (status != 'ENDED' AND end_date IS NULL)
                )
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE employments
                ADD CONSTRAINT chk_employments_cancelled_at_matches_status
                CHECK (
                    (status = 'CANCELLED' AND cancelled_at IS NOT NULL)
                    OR (status != 'CANCELLED' AND cancelled_at IS NULL)
                )
                SQL,
        );

        DB::statement(
            <<<'SQL'
                ALTER TABLE employments
                ADD CONSTRAINT chk_employments_end_date_after_start
                CHECK (end_date IS NULL OR end_date >= start_date)
                SQL,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('employments');
    }
};
