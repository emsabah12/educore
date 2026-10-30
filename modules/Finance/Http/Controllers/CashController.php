<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        return Inertia::render('finance/cash', [
            'books' => $books,
            'bookKey' => $key,
            'months' => $months,
            'month' => $month,
            'accountId' => $accountId,
            'accounts' => $accountRows,
            'ledger' => $book->ledger($tenantId, $organizationId, $month, $accountId),
            'incomeCategories' => $incomeCategories,
            'canManage' => $this->allowsAt($organizationId, FinanceAccess::CASH_MANAGE),
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
