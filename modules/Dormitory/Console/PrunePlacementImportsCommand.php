<?php

namespace Modules\Dormitory\Console;

use Illuminate\Console\Command;
use Modules\Dormitory\Application\Import\PendingPlacementImport;

/**
 * Menghapus file impor penempatan sementara yang lebih tua dari 24 jam (PRD-008 §20). Dijadwalkan tiap jam oleh
 * DormitoryServiceProvider.
 */
final class PrunePlacementImportsCommand extends Command
{
    protected $signature = 'dormitory:prune-imports';

    protected $description = 'Hapus file impor penempatan asrama sementara yang lebih tua dari 24 jam';

    public function handle(PendingPlacementImport $store): int
    {
        $deleted = $store->prune();

        $this->components->info("{$deleted} file impor penempatan sementara dihapus.");

        return self::SUCCESS;
    }
}
