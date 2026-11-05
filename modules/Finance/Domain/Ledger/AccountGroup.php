<?php

namespace Modules\Finance\Domain\Ledger;

/**
 * Golongan akun COA (PRD-007 §3). Saldo normal bawaan: Aset & Beban di debit, lainnya di kredit; akun kontra
 * (mis. akumulasi penyusutan, potongan & beasiswa) memakai saldo normal kebalikannya.
 */
enum AccountGroup: string
{
    case Asset = 'ASET';
    case Liability = 'LIABILITAS';
    case NetAssets = 'ASET_NETO';
    case Revenue = 'PENDAPATAN';
    case Expense = 'BEBAN';

    public function label(): string
    {
        return match ($this) {
            self::Asset => 'Aset',
            self::Liability => 'Liabilitas',
            self::NetAssets => 'Aset neto',
            self::Revenue => 'Pendapatan',
            self::Expense => 'Beban',
        };
    }

    public function normalBalance(): NormalBalance
    {
        return match ($this) {
            self::Asset, self::Expense => NormalBalance::Debit,
            default => NormalBalance::Credit,
        };
    }

    /** Digit pertama kode akun yang lazim (hanya saran di formulir, tidak dipaksa). */
    public function codePrefix(): string
    {
        return match ($this) {
            self::Asset => '1',
            self::Liability => '2',
            self::NetAssets => '3',
            self::Revenue => '4',
            self::Expense => '5',
        };
    }
}
