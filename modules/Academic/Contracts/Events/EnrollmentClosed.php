<?php

namespace Modules\Academic\Contracts\Events;

/**
 * Kejadian publik Academic (PRD-002 §4.2): sebuah pendaftaran siswa di lembaga ditutup (keluar, pindah,
 * lulus, dst.). Dikirim SINKRON di dalam transaksi penutupan, sehingga modul lain (mis. Finance,
 * PRD-003 OD-FN-07) bisa ikut menyesuaikan datanya; bila pendengar gagal, penutupan ikut dibatalkan.
 */
final readonly class EnrollmentClosed
{
    public function __construct(
        public string $tenantId,
        public string $organizationId,
        public string $enrollmentId,
        /** Tanggal keluar, Y-m-d. */
        public string $endDate,
    ) {}
}
