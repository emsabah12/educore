<?php

namespace Modules\Academic\Console;

use Illuminate\Console\Command;
use Modules\Academic\Application\Import\PendingStudentImport;

/**
 * Menghapus file impor siswa yang tertinggal (diunggah tetapi tidak disimpan/dibatalkan) lebih dari
 * 24 jam (PRD-002 AC-1e). Dijadwalkan tiap jam oleh AcademicServiceProvider.
 */
final class PruneStudentImportsCommand extends Command
{
    protected $signature = 'academic:prune-imports';

    protected $description = 'Hapus file impor siswa sementara yang lebih tua dari 24 jam';

    public function handle(PendingStudentImport $store): int
    {
        $deleted = $store->prune();

        $this->components->info("{$deleted} file impor siswa sementara dihapus.");

        return self::SUCCESS;
    }
}
