<?php

namespace Modules\Finance\Application\Ledger;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Application\Fee\Rupiah;
use Modules\Finance\Domain\Fee\Exceptions\FinanceException;
use Modules\Finance\Domain\Ledger\NormalBalance;
use stdClass;

/**
 * Baris saldo awal tambahan dari akuntan (PRD-007 §5, PB-2a): aset tetap, utang lain, aset neto dengan pembatasan, dll.
 * per lembaga / Yayasan. Digabung ke jurnal SALDO_AWAL lembaganya (JournalProjector). Hak akses (finance.ledger.manage
 * tingkat Yayasan) dicek pemanggil.
 *
 * [ASUMSI PB-2a] boleh ditambah, diubah, dihapus kapan saja sampai ada kunci periode (PB-4); setiap perubahan diaudit.
 *
 * @phpstan-type ItemRow array{id: string, account_id: string, side: string, amount: int, note: string|null}
 */
final class OpeningItems
{
    public const NOTE_MAX_LENGTH = 100;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ChartOfAccounts $accounts,
        private readonly JournalProjector $projector,
        private readonly LedgerLock $ledgerLock,
    ) {}

    /**
     * @return list<ItemRow>
     */
    public function all(string $tenantId, ?string $organizationId): array
    {
        $rows = [];

        foreach (DB::table('fn_opening_items')
            ->where('tenant_id', $tenantId)
            ->when($organizationId === null, fn ($query) => $query->whereNull('organization_id'), fn ($query) => $query->where('organization_id', $organizationId))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'account_id', 'debit', 'credit', 'note']) as $row) {
            $rows[] = [
                'id' => (string) $row->id,
                'account_id' => (string) $row->account_id,
                'side' => (int) $row->debit > 0 ? NormalBalance::Debit->value : NormalBalance::Credit->value,
                'amount' => max((int) $row->debit, (int) $row->credit),
                'note' => $row->note === null ? null : (string) $row->note,
            ];
        }

        return $rows;
    }

    /**
     * @throws FinanceException
     */
    public function add(string $tenantId, ?string $membershipId, ?string $organizationId, string $accountId, string $side, mixed $amount, string $note): string
    {
        $this->assertActive($tenantId);
        $organizationId = $this->accounts->ledgerOrganization($tenantId, $organizationId, 'organization_id');
        [$debit, $credit, $note] = $this->values($tenantId, $accountId, $side, $amount, $note);
        $id = (string) Str::uuid7();

        DB::transaction(function () use ($tenantId, $membershipId, $organizationId, $accountId, $debit, $credit, $note, $id): void {
            DB::table('fn_opening_items')->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'organization_id' => $organizationId,
                'account_id' => $accountId,
                'debit' => $debit,
                'credit' => $credit,
                'note' => $note,
                'recorded_by_membership_id' => $membershipId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->changed($tenantId, $organizationId, $id, 'tambah', $accountId, $debit, $credit);
        });

        return $id;
    }

    /**
     * @throws FinanceException
     */
    public function update(string $tenantId, string $itemId, string $accountId, string $side, mixed $amount, string $note): void
    {
        $this->assertActive($tenantId);
        [$debit, $credit, $note] = $this->values($tenantId, $accountId, $side, $amount, $note);

        DB::transaction(function () use ($tenantId, $itemId, $accountId, $debit, $credit, $note): void {
            $item = $this->lock($tenantId, $itemId);

            DB::table('fn_opening_items')->where('id', $itemId)->update([
                'account_id' => $accountId,
                'debit' => $debit,
                'credit' => $credit,
                'note' => $note,
                'updated_at' => now(),
            ]);

            $this->changed($tenantId, $item->organization_id === null ? null : (string) $item->organization_id, $itemId, 'ubah', $accountId, $debit, $credit);
        });
    }

    /**
     * @throws FinanceException
     */
    public function remove(string $tenantId, string $itemId): void
    {
        $this->assertActive($tenantId);

        DB::transaction(function () use ($tenantId, $itemId): void {
            $item = $this->lock($tenantId, $itemId);
            DB::table('fn_opening_items')->where('id', $itemId)->delete();

            $this->changed($tenantId, $item->organization_id === null ? null : (string) $item->organization_id, $itemId, 'hapus', (string) $item->account_id, (int) $item->debit, (int) $item->credit);
        });
    }

    /**
     * @return array{0: int, 1: int, 2: string|null}
     *
     * @throws FinanceException
     */
    private function values(string $tenantId, string $accountId, string $side, mixed $amount, string $note): array
    {
        $this->accounts->assertManualAccount($tenantId, $accountId, true, 'account_id');
        $side = NormalBalance::tryFrom($side);

        if ($side === null) {
            throw FinanceException::make('side', 'Pilih posisi saldo: debit atau kredit.');
        }

        $value = Rupiah::parse($amount, 'amount', 'nominal saldo awal', 1, ManualJournals::MAX_AMOUNT);
        $note = trim((string) preg_replace('/\s+/u', ' ', $note));

        if (mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            throw FinanceException::make('note', 'Keterangan maksimal 100 karakter.');
        }

        return [$side === NormalBalance::Debit ? $value : 0, $side === NormalBalance::Credit ? $value : 0, $note === '' ? null : $note];
    }

    private function changed(string $tenantId, ?string $organizationId, string $itemId, string $action, string $accountId, int $debit, int $credit): void
    {
        $this->projector->queueOpening($tenantId, $organizationId);

        $this->audit->record(
            FinanceAuditAction::OPENING_BALANCE_SET,
            FinanceAuditAction::SUBJECT_LEDGER_SETTINGS,
            $tenantId,
            [
                'aksi' => $action,
                'baris' => $itemId,
                'lembaga' => $organizationId,
                'akun' => DB::table('fn_ledger_accounts')->where('id', $accountId)->value('code'),
                'debit' => $debit,
                'kredit' => $credit,
            ],
            tenantId: $tenantId,
        );
    }

    /**
     * @throws FinanceException
     */
    private function assertActive(string $tenantId): void
    {
        $start = DB::table('fn_ledger_settings')->where('tenant_id', $tenantId)->value('start_date');

        if ($start === null) {
            throw FinanceException::make('form', 'Atur tanggal mulai pembukuan dulu.');
        }

        // Jurnal saldo awal bertanggal tanggal mulai: begitu bulan itu ditutup buku, saldo awal ikut terkunci.
        $this->ledgerLock->assertChangeable($tenantId, substr((string) $start, 0, 10));
    }

    /**
     * @throws FinanceException
     */
    private function lock(string $tenantId, string $itemId): stdClass
    {
        $item = Str::isUuid($itemId)
            ? DB::table('fn_opening_items')->where('tenant_id', $tenantId)->where('id', $itemId)->lockForUpdate()->first()
            : null;

        if ($item === null) {
            throw FinanceException::make('form', 'Baris saldo awal tidak ditemukan.');
        }

        return $item;
    }
}
