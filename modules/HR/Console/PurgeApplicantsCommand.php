<?php

namespace Modules\HR\Console;

use Illuminate\Console\Command;
use Modules\HR\Application\Recruitment\PurgeApplicants;

/**
 * Menghapus data pelamar yang tidak menjadi pegawai pada lowongan yang ditutup lebih dari 1 tahun (PRD-001 §20.2,
 * OD-HR-21). Dijadwalkan harian oleh HRServiceProvider.
 */
final class PurgeApplicantsCommand extends Command
{
    protected $signature = 'hr:purge-applicants';

    protected $description = 'Hapus data pelamar yang tidak diterima dari lowongan yang ditutup lebih dari 1 tahun';

    public function handle(PurgeApplicants $purge): int
    {
        $deleted = $purge->run();

        $this->components->info("{$deleted} data pelamar dihapus.");

        return self::SUCCESS;
    }
}
