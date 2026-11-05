<?php

namespace Modules\Finance\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Application\Ledger\JournalProjector;

/**
 * Susun jurnal otomatis dari antrean pembukuan, dan selesaikan susun ulang yang tertunda (PRD-007 §4, §13; PB-1).
 * Dijadwalkan tiap menit oleh FinanceServiceProvider; halaman pembukuan juga memproses antrean sebelum tampil.
 */
final class ProcessLedgerCommand extends Command
{
    protected $signature = 'finance:ledger-process {--rebuild : Susun ulang semua jurnal otomatis walaupun pengaturan tidak berubah}';

    protected $description = 'Susun jurnal otomatis pembukuan dari transaksi yang berubah';

    public function handle(JournalProjector $projector): int
    {
        foreach (DB::table('fn_ledger_settings')->whereNotNull('start_date')->get(['tenant_id', 'config_version', 'rebuilt_version', 'rebuild_version']) as $settings) {
            $tenantId = (string) $settings->tenant_id;

            if ($this->option('rebuild') && $settings->rebuild_version === null) {
                DB::table('fn_ledger_settings')->where('tenant_id', $tenantId)->update(['config_version' => DB::raw('config_version + 1')]);
            }

            if ($this->option('rebuild') || $settings->rebuild_version !== null || (int) $settings->rebuilt_version < (int) $settings->config_version) {
                $projector->rebuildAll($tenantId);
            }

            do {
                $remaining = $projector->processQueue($tenantId, 10000);
            } while ($remaining > 0);
        }

        $this->components->info('Jurnal otomatis sudah diperbarui.');

        return self::SUCCESS;
    }
}
