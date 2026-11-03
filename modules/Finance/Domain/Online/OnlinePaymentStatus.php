<?php

namespace Modules\Finance\Domain\Online;

/**
 * Status pesanan bayar online (PRD-006 §3).
 */
enum OnlinePaymentStatus: string
{
    case Pending = 'MENUNGGU';
    case Paid = 'LUNAS';
    case Expired = 'KEDALUWARSA';
    case Failed = 'GAGAL';
    case Cancelled = 'DIBATALKAN';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu pembayaran',
            self::Paid => 'Lunas',
            self::Expired => 'Kedaluwarsa',
            self::Failed => 'Gagal',
            self::Cancelled => 'Dibatalkan',
        };
    }
}
