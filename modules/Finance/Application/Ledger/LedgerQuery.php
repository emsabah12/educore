<?php

namespace Modules\Finance\Application\Ledger;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Domain\Payment\PaymentStatus;

/**
 * Potongan query bersama laporan pembukuan (PRD-007 §7): baris jurnal SAH (`l` + `j`), saringan lingkup, tanggal
 * efektif.
 */
final class LedgerQuery
{
    /**
     * Tanggal efektif baris jurnal: jurnal saldo awal (bertanggal tanggal mulai) dihitung sebagai saldo SEBELUM tanggal
     * mulai, supaya tampil sebagai saldo awal periode, bukan mutasi.
     */
    public const EFFECTIVE_DATE = "(j.journal_date - CASE WHEN j.source = 'SALDO_AWAL' THEN 1 ELSE 0 END)";

    /**
     * Baris jurnal SAH yayasan ini (alias `l` = baris, `j` = jurnal).
     */
    public static function validLines(string $tenantId): Builder
    {
        return DB::table('fn_journal_lines as l')
            ->join('fn_journals as j', 'j.id', '=', 'l.journal_id')
            ->where('l.tenant_id', $tenantId)
            ->where('j.status', PaymentStatus::Valid->value);
    }

    /**
     * Lingkup: null = gabungan; '' = Yayasan pusat; id = satu lembaga.
     */
    public static function scoped(Builder $query, ?string $scope): Builder
    {
        return match ($scope) {
            null => $query,
            '' => $query->whereNull('l.organization_id'),
            default => $query->where('l.organization_id', $scope),
        };
    }
}
