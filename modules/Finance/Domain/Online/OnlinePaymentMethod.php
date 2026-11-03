<?php

namespace Modules\Finance\Domain\Online;

/**
 * Metode pembayaran online yang bisa dibuka (PRD-006 OD-MG-02: VA bank + QRIS). `snapCode()` = nilai
 * `enabled_payments` Snap Midtrans; satu transaksi Snap hanya membuka satu metode, karena biaya layanan bergantung
 * pada metode (OD-MG-03).
 */
enum OnlinePaymentMethod: string
{
    case BcaVa = 'BCA_VA';
    case BniVa = 'BNI_VA';
    case BriVa = 'BRI_VA';
    case MandiriBill = 'MANDIRI_BILL';
    case PermataVa = 'PERMATA_VA';
    case Qris = 'QRIS';

    public function label(): string
    {
        return match ($this) {
            self::BcaVa => 'VA BCA',
            self::BniVa => 'VA BNI',
            self::BriVa => 'VA BRI',
            self::MandiriBill => 'Mandiri Bill',
            self::PermataVa => 'VA Permata',
            self::Qris => 'QRIS',
        };
    }

    public function snapCode(): string
    {
        return match ($this) {
            self::BcaVa => 'bca_va',
            self::BniVa => 'bni_va',
            self::BriVa => 'bri_va',
            self::MandiriBill => 'echannel',
            self::PermataVa => 'permata_va',
            self::Qris => 'other_qris',
        };
    }

    /** Biaya QRIS dalam persen (disimpan sebagai basis poin); VA nominal rupiah tetap. */
    public function feeIsPercent(): bool
    {
        return $this === self::Qris;
    }

    /**
     * Biaya layanan untuk jumlah tagihan, dibulatkan ke atas ke rupiah (PRD-006 §6).
     *
     * @param  int  $feeValue  rupiah (VA) atau basis poin (QRIS, 70 = 0,70%)
     */
    public function fee(int $invoiceAmount, int $feeValue): int
    {
        return $this->feeIsPercent() ? intdiv($invoiceAmount * $feeValue + 9999, 10000) : $feeValue;
    }

    /** Batas waktu bayar dalam menit (PRD-006 OD-MG-05: VA 24 jam, QRIS 15 menit). */
    public function expiryMinutes(): int
    {
        return $this === self::Qris ? 15 : 24 * 60;
    }
}
