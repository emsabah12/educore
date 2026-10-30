<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Authorization\FinanceAccess;
use Modules\Finance\Application\Cash\CashBook;
use Modules\Finance\Application\Cash\ManageCashAccounts;
use Modules\Finance\Application\Cash\ManageCashCategories;
use Modules\Finance\Application\Cash\ManageCashEntries;
use Modules\Finance\Application\Cash\ManageCashTransfers;
use Modules\Finance\Application\Cash\PaymentCashSync;
use Modules\Finance\Application\Report\ReceiptRecap;
use Modules\Finance\Domain\Cash\CashDirection;
use Modules\Finance\Http\Controllers\Concerns\WorksPerCashBook;
use Modules\Finance\Http\Controllers\Concerns\WorksPerOrganization;

/**
 * Kas umum (PRD-004 §3, §5–§6, KS-1): pilih kas (lembaga / Yayasan) → rekening & saldo, buku kas per bulan;
 * tambah/ubah rekening & saldo awal, catat pemasukan, batalkan transaksi manual.
 *
 * - Kas lembaga: `finance.cash.view` / `finance.cash.manage` di lembaga itu (di luar cakupan → 404, tanpa izin → 403).
 * - Kas Yayasan (`kas=yayasan`): izin yang sama di tingkat Yayasan.
 */
final class CashController
{
    use WorksPerCashBook;
    use WorksPerOrganization;

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, CashBook $book, ManageCashAccounts $accounts, ManageCashCategories $categories, PaymentCashSync $sync): Response
    {
        $books = $this->books();

        if ($books === []) {
            abort(403);
        }

        $key = $this->pick(array_column($books, 'value'), $request->query('kas')) ?? $books[0]['value'];
        $organizationId = $key === self::YAYASAN_KEY ? null : $key;
        $tenantId = $this->tenantId();

        $categories->ensureDefaults($tenantId);
        $accounts->ensureCashAccount($tenantId, $organizationId);

        // KS-2: kuitansi kasir lembaga ini (termasuk riwayat sebelum KS-2) masuk buku kas.
        if ($organizationId !== null) {
            $sync->syncOrganization($tenantId, $organizationId);
        }

        $accountRows = $book->accounts($tenantId, $organizationId);
        $months = ReceiptRecap::monthOptions(BusinessClock::today());
        $month = $this->pick(array_column($months, 'value'), $request->query('bulan')) ?? BusinessClock::today()->format('Y-m');
        $accountId = $this->pick(array_column($accountRows, 'id'), $request->query('rekening'), '') ?: null;

        $incomeCategories = [];

        foreach ($categories->all($tenantId) as $category) {
            if ($category['direction'] === CashDirection::In->value && $category['is_active'] && ! $category['is_system']) {
                $incomeCategories[] = ['value' => $category['id'], 'label' => $category['name']];
            }
        }

        $canManage = $this->allowsAt($organizationId, FinanceAccess::CASH_MANAGE);

        return Inertia::render('finance/cash', [
            'books' => $books,
            'bookKey' => $key,
            'months' => $months,
            'month' => $month,
            'accountId' => $accountId,
            'accounts' => $accountRows,
            'ledger' => $book->ledger($tenantId, $organizationId, $month, $accountId),
            'incomeCategories' => $incomeCategories,
            'canManage' => $canManage,
            // KS-4: rekening tujuan mutasi dari kas ini (kas sendiri + kas Yayasan / kas lembaga).
            'transferTargets' => $canManage ? $this->transferTargets($accounts, $tenantId, $organizationId) : [],
            // KS-2: kas lembaga menerima pembayaran siswa; tanpa rekening penerima transfer → transfer masuk Kas tunai.
            'receivesPayments' => $organizationId !== null,
            'today' => BusinessClock::todayString(),
            'limits' => [
                'name' => ManageCashAccounts::NAME_MAX_LENGTH,
                'bank' => ManageCashAccounts::BANK_MAX_LENGTH,
                'description' => ManageCashEntries::DESCRIPTION_MAX_LENGTH,
                'reason' => ManageCashEntries::REASON_MAX_LENGTH,
            ],
        ]);
    }

    public function storeAccount(Request $request, ManageCashAccounts $accounts): RedirectResponse
    {
        $organizationId = $this->bookFromRequest($request, FinanceAccess::CASH_MANAGE);

        $this->attempt(fn (): string => $accounts->createBank(
            $this->tenantId(),
            $organizationId,
            $request->string('name')->toString(),
            $request->string('bank_name')->toString(),
            $request->string('account_number')->toString(),
            $request->input('opening_balance'),
            $request->string('opening_date')->toString(),
            $this->tenantContext->require()->membershipId,
        ));

        return $this->done('Rekening ditambahkan.');
    }

    public function updateAccount(Request $request, string $account, ManageCashAccounts $accounts, PaymentCashSync $sync): RedirectResponse
    {
        $owner = $accounts->ownerOf($this->tenantId(), $account);

        if ($owner === false) {
            abort(404);
        }

        $this->authorizeBook($owner['organization_id'], FinanceAccess::CASH_MANAGE);

        $this->attempt(fn () => $accounts->update(
            $this->tenantId(),
            $account,
            $request->string('name')->toString(),
            $request->string('bank_name')->toString(),
            $request->string('account_number')->toString(),
            $request->input('opening_balance'),
            $request->string('opening_date')->toString(),
        ));

        // Saldo awal berubah → cerminan kuitansi kasir disusun ulang (KS-2).
        if ($owner['organization_id'] !== null) {
            $sync->syncOrganization($this->tenantId(), $owner['organization_id']);
        }

        return $this->done('Rekening disimpan.');
    }

    /**
     * Tandai / lepas rekening bank lembaga sebagai penerima pembayaran siswa via transfer (KS-2).
     */
    public function setTransferReceiver(Request $request, string $account, ManageCashAccounts $accounts, PaymentCashSync $sync): RedirectResponse
    {
        $owner = $accounts->ownerOf($this->tenantId(), $account);

        if ($owner === false) {
            abort(404);
        }

        $this->authorizeBook($owner['organization_id'], FinanceAccess::CASH_MANAGE);
        $receives = $request->boolean('receives');

        $this->attempt(fn () => $accounts->setTransferReceiver($this->tenantId(), $account, $receives));

        if ($owner['organization_id'] !== null) {
            $sync->syncOrganization($this->tenantId(), $owner['organization_id']);
        }

        return $this->done($receives ? 'Rekening ini menerima pembayaran transfer siswa.' : 'Pembayaran transfer siswa kembali dicatat ke Kas tunai.');
    }

    public function storeIncome(Request $request, ManageCashEntries $entries): RedirectResponse
    {
        $organizationId = $this->bookFromRequest($request, FinanceAccess::CASH_MANAGE);

        $this->attempt(fn (): string => $entries->recordIncome(
            $this->tenantId(),
            $organizationId,
            $request->string('account_id')->toString(),
            $request->string('entry_date')->toString(),
            $request->string('category_id')->toString(),
            $request->input('amount'),
            $request->string('description')->toString(),
            $this->tenantContext->require()->membershipId,
        ));

        return $this->done('Pemasukan dicatat.');
    }

    /**
     * Mutasi antar-rekening / antar-kas (KS-4). Izin: finance.cash.manage di kas rekening ASAL.
     */
    public function storeTransfer(Request $request, ManageCashAccounts $accounts, ManageCashTransfers $transfers): RedirectResponse
    {
        $from = $request->string('account_id')->toString();
        $owner = $accounts->ownerOf($this->tenantId(), $from);

        if ($owner === false) {
            abort(404);
        }

        $this->authorizeBook($owner['organization_id'], FinanceAccess::CASH_MANAGE);

        $this->attempt(fn (): string => $transfers->record(
            $this->tenantId(),
            $from,
            $request->string('to_account_id')->toString(),
            $request->string('entry_date')->toString(),
            $request->input('amount'),
            $request->string('description')->toString(),
            $this->tenantContext->require()->membershipId,
        ));

        return $this->done('Mutasi dicatat.');
    }

    public function voidTransfer(Request $request, string $transfer, ManageCashTransfers $transfers): RedirectResponse
    {
        $owner = $transfers->ownerOf($this->tenantId(), $transfer);

        if ($owner === false) {
            abort(404);
        }

        $this->authorizeBook($owner['organization_id'], FinanceAccess::CASH_MANAGE);
        $this->attempt(fn () => $transfers->void($this->tenantId(), $transfer, $request->string('reason')->toString(), $this->tenantContext->require()->membershipId));

        return $this->done('Mutasi dibatalkan.');
    }

    /**
     * Rekening aktif yang bisa menjadi tujuan mutasi dari kas ini: rekening kas sendiri, lalu kas Yayasan (dari kas
     * lembaga) atau semua kas lembaga (dari kas Yayasan).
     *
     * @return list<array{value: string, label: string}>
     */
    private function transferTargets(ManageCashAccounts $accounts, string $tenantId, ?string $organizationId): array
    {
        if ($organizationId !== null) {
            $accounts->ensureCashAccount($tenantId, null);
        }

        $targets = [];

        foreach (DB::table('fn_cash_accounts as a')
            ->leftJoin('organizations as o', 'o.id', '=', 'a.organization_id')
            ->where('a.tenant_id', $tenantId)
            ->where('a.is_active', true)
            // Dari kas Yayasan: semua kas (sendiri + lembaga). Dari kas lembaga: kas sendiri + kas Yayasan.
            ->when($organizationId !== null, fn ($query) => $query->where(fn ($where) => $where->where('a.organization_id', $organizationId)->orWhereNull('a.organization_id')))
            ->orderByRaw('CASE WHEN a.organization_id IS NOT DISTINCT FROM CAST(? AS uuid) THEN 0 WHEN a.organization_id IS NULL THEN 1 ELSE 2 END', [$organizationId])
            ->orderBy('o.name')
            ->orderByRaw("a.kind <> 'TUNAI'")
            ->orderBy('a.name')
            ->get(['a.id', 'a.name', 'a.organization_id', 'o.name as organization']) as $account) {
            $own = $account->organization_id === $organizationId;
            $kas = $account->organization_id === null ? 'Kas Yayasan' : (string) $account->organization;
            $targets[] = ['value' => (string) $account->id, 'label' => $own ? (string) $account->name : ((string) $account->name).' — '.$kas];
        }

        return $targets;
    }

    public function voidEntry(Request $request, string $entry, ManageCashEntries $entries): RedirectResponse
    {
        $owner = $entries->ownerOf($this->tenantId(), $entry);

        if ($owner === false) {
            abort(404);
        }

        $this->authorizeBook($owner['organization_id'], FinanceAccess::CASH_MANAGE);

        $this->attempt(fn () => $entries->void($this->tenantId(), $entry, $request->string('reason')->toString(), $this->tenantContext->require()->membershipId));

        return $this->done('Transaksi dibatalkan.');
    }
}
