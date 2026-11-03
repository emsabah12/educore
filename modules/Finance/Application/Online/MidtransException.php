<?php

namespace Modules\Finance\Application\Online;

use RuntimeException;

/**
 * Midtrans tidak bisa dihubungi atau menolak permintaan (PRD-006 §12). Pesannya aman ditampilkan ke pengguna; rincian
 * teknis dicatat di log, bukan di pesan.
 */
final class MidtransException extends RuntimeException
{
    /** Kode: 503 = tidak bisa dihubungi / error server Midtrans; 400 = ditolak (HTTP 4xx); 500 = kunci belum dipasang. */
    public static function unreachable(): self
    {
        return new self('Layanan pembayaran sedang tidak bisa dihubungi. Coba lagi beberapa saat lagi.', 503);
    }

    public static function rejected(): self
    {
        return new self('Layanan pembayaran menolak permintaan ini. Hubungi bendahara sekolah.', 400);
    }

    public static function notConfigured(): self
    {
        return new self('Pembayaran online belum disiapkan (kunci Midtrans belum dipasang).', 500);
    }

    /** Midtrans menjawab tetapi menolak (mis. transaksi belum/tidak ada); bukan gangguan jaringan. */
    public function isRejection(): bool
    {
        return $this->getCode() === 400;
    }
}
