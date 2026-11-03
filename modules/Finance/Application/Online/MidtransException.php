<?php

namespace Modules\Finance\Application\Online;

use RuntimeException;

/**
 * Midtrans tidak bisa dihubungi atau menolak permintaan (PRD-006 §12). Pesannya aman ditampilkan ke pengguna; rincian
 * teknis dicatat di log, bukan di pesan.
 */
final class MidtransException extends RuntimeException
{
    public static function unreachable(): self
    {
        return new self('Layanan pembayaran sedang tidak bisa dihubungi. Coba lagi beberapa saat lagi.');
    }

    public static function rejected(): self
    {
        return new self('Layanan pembayaran menolak permintaan ini. Hubungi bendahara sekolah.');
    }

    public static function notConfigured(): self
    {
        return new self('Pembayaran online belum disiapkan (kunci Midtrans belum dipasang).');
    }
}
