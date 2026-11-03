<?php

namespace Modules\Finance\Application\Savings;

use Illuminate\Support\Facades\DB;
use Modules\Finance\Domain\Payment\PaymentMethod;
use Modules\Finance\Domain\Payment\PaymentStatus;
use Modules\Finance\Domain\Savings\SavingsEntryKind;

/**
 * Tabungan satu siswa di satu lembaga untuk tab Tabungan di Kasir (PRD-005 §4, TB-1): saldo dan riwayat terakhir.
 * Hak akses (finance.savings.view) dicek pemanggil.
 *
 * @phpstan-type SavingsRow array{id: string, slip_number: string, entry_date: string, kind: string, kind_label: string, method: string|null, amount: int, balance_after: int, taken_by: string|null, note: string|null, status: string, void_reason: string|null}
 */
final class SavingsOverview
{
    /** Riwayat yang ditampilkan di Kasir. */
    public const HISTORY_LIMIT = 30;

    /**
     * @param  bool  $validOnly  hanya transaksi SAH (portal orang tua, TB-4)
     * @return array{exists: bool, balance: int, entries: list<SavingsRow>}
     */
    public function forStudent(string $tenantId, string $organizationId, string $studentId, bool $validOnly = false): array
    {
        $account = DB::table('fn_savings_accounts')
            ->where('tenant_id', $tenantId)
            ->where('organization_id', $organizationId)
            ->where('student_id', $studentId)
            ->first(['id', 'balance']);

        if ($account === null) {
            return ['exists' => false, 'balance' => 0, 'entries' => []];
        }

        $entries = [];

        foreach (DB::table('fn_savings_entries')
            ->where('savings_account_id', $account->id)
            ->when($validOnly, fn ($query) => $query->where('status', PaymentStatus::Valid->value))
            ->orderByDesc('entry_date')
            ->orderByDesc('slip_year')
            ->orderByDesc('slip_sequence')
            ->limit(self::HISTORY_LIMIT)
            ->get(['id', 'slip_number', 'entry_date', 'kind', 'method', 'amount', 'balance_after', 'taken_by', 'note', 'status', 'void_reason']) as $row) {
            $kind = SavingsEntryKind::from((string) $row->kind);
            $entries[] = [
                'id' => (string) $row->id,
                'slip_number' => (string) $row->slip_number,
                'entry_date' => substr((string) $row->entry_date, 0, 10),
                'kind' => $kind->value,
                'kind_label' => $kind->label(),
                'method' => $row->method === null ? null : (PaymentMethod::tryFrom((string) $row->method) ?? PaymentMethod::Cash)->label(),
                'amount' => (int) $row->amount,
                'balance_after' => (int) $row->balance_after,
                'taken_by' => $row->taken_by === null ? null : (string) $row->taken_by,
                'note' => $row->note === null ? null : (string) $row->note,
                'status' => (string) $row->status,
                'void_reason' => $row->void_reason === null ? null : (string) $row->void_reason,
            ];
        }

        return ['exists' => true, 'balance' => (int) $account->balance, 'entries' => $entries];
    }
}
