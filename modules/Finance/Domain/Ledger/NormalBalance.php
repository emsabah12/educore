<?php

namespace Modules\Finance\Domain\Ledger;

enum NormalBalance: string
{
    case Debit = 'DEBIT';
    case Credit = 'KREDIT';

    public function label(): string
    {
        return match ($this) {
            self::Debit => 'Debit',
            self::Credit => 'Kredit',
        };
    }
}
