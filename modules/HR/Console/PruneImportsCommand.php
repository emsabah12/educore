<?php

namespace Modules\HR\Console;

use Illuminate\Console\Command;
use Modules\HR\Application\Import\PendingImportStore;

/**
 * Menghapus file impor pegawai yang tertinggal (diunggah tetapi tidak disimpan/dibatalkan)
 * lebih dari 24 jam (PRD-001 §6.2). Dijadwalkan tiap jam oleh HRServiceProvider.
 */
final class PruneImportsCommand extends Command
{
    protected $signature = 'hr:prune-imports';

    protected $description = 'Hapus file impor pegawai sementara yang lebih tua dari 24 jam';

    public function handle(PendingImportStore $store): int
    {
        $deleted = $store->prune();

        $this->components->info("{$deleted} file impor sementara dihapus.");

        return self::SUCCESS;
    }
}
