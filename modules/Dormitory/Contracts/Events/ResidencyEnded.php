<?php

namespace Modules\Dormitory\Contracts\Events;

/**
 * Kejadian publik Dormitory (PRD-008 OD-DM-10, PRD-003 §20): penempatan seorang santri berakhir (check-out,
 * proses akhir tahun "Kosongkan", atau mutasi). Dikirim SINKRON di dalam transaksi yang mengakhirinya — sesudah
 * penempatan baru (mutasi) tersimpan — sehingga pendengar (mis. Finance) bisa memeriksa apakah santri itu masih
 * tinggal di asrama lain; bila pendengar gagal, perubahan asrama ikut dibatalkan.
 */
final readonly class ResidencyEnded
{
    public function __construct(
        public string $tenantId,
        public string $membershipId,
        /** Tanggal keluar penempatan, Y-m-d. */
        public string $endDate,
    ) {}
}
