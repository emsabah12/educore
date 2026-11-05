<?php

namespace Modules\Finance\Domain\Ledger;

/**
 * Aktivitas arus kas untuk akun non-kas (PRD-007 §3, dipakai laporan arus kas metode langsung PB-3).
 */
enum CashFlowActivity: string
{
    case Operating = 'OPERASI';
    case Investing = 'INVESTASI';
    case Financing = 'PENDANAAN';

    public function label(): string
    {
        return match ($this) {
            self::Operating => 'Operasi',
            self::Investing => 'Investasi',
            self::Financing => 'Pendanaan',
        };
    }
}
