<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Audit\AuditLogger;

/**
 * Menghapus audit log yang melewati masa simpan (OD-16, default 365 hari).
 * Dijadwalkan harian oleh CoreServiceProvider; butuh `schedule:run` di cron/Task Scheduler.
 */
final class PruneAuditCommand extends Command
{
    public const DEFAULT_DAYS = 365;

    protected $signature = 'educore:prune-audit {--days= : Masa simpan dalam hari (default dari AUDIT_RETENTION_DAYS atau 365)}';

    protected $description = 'Hapus audit log yang lebih tua dari masa simpan';

    public function handle(AuditLogger $audit): int
    {
        $option = $this->option('days');

        // Opsi dari terminal selalu teks; dari kode (Artisan::call) bisa berupa angka.
        if ($option !== null && ! ctype_digit((string) $option)) {
            $this->components->error('--days harus berupa angka hari, mis. --days=365.');

            return self::FAILURE;
        }

        $days = $option !== null ? (int) $option : (int) config('educore.audit_retention_days', self::DEFAULT_DAYS);

        if ($days < 30) {
            $this->components->error('Masa simpan minimal 30 hari.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        $deleted = DB::transaction(function () use ($cutoff, $days, $audit): int {
            // Membuka pengecualian append-only HANYA untuk transaksi ini (lihat migrasi audit_logs).
            DB::statement("SET LOCAL educore.audit_prune = 'on'");

            $deleted = DB::table('audit_logs')->where('created_at', '<', $cutoff)->delete();

            $audit->record(AuditAction::AUDIT_PRUNED, changes: ['days' => $days, 'deleted' => $deleted], global: true);

            return $deleted;
        });

        $this->components->info("{$deleted} baris audit log yang lebih tua dari {$days} hari dihapus.");

        return self::SUCCESS;
    }
}
