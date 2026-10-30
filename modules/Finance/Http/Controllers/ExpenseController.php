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
use Modules\Finance\Application\Cash\ExpenseFileStore;
use Modules\Finance\Application\Cash\ExpenseList;
use Modules\Finance\Application\Cash\ManageCashCategories;
use Modules\Finance\Application\Cash\ManageExpenses;
use Modules\Finance\Domain\Cash\CashDirection;
use Modules\Finance\Domain\Cash\ExpenseStatus;
use Modules\Finance\Http\Controllers\Concerns\WorksPerCashBook;
use Modules\Finance\Http\Controllers\Concerns\WorksPerOrganization;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Pengeluaran (PRD-004 §4–§6, KS-3): daftar pengajuan per kas, ajukan, batalkan, setujui/tolak, realisasi,
 * batalkan realisasi, unduh lampiran/bukti.
 *
 * - Lihat: `finance.cash.view` di kas itu; ajukan/batal/realisasi: `finance.cash.manage`;
 *   setujui/tolak: `finance.expenses.approve` (bukan pengajuan sendiri). Kas di luar cakupan → 404, tanpa izin → 403.
 */
final class ExpenseController
{
    use WorksPerCashBook;
    use WorksPerOrganization;

    /** Nilai saringan kas untuk semua kas yang terlihat. */
    private const ALL = 'semua';

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, ExpenseList $list, ManageCashCategories $categories, CashBook $book): Response
    {
        $books = $this->books();

        if ($books === []) {
            abort(403);
        }

        $tenantId = $this->tenantId();
        $membershipId = $this->tenantContext->require()->membershipId;
        $categories->ensureDefaults($tenantId);

        $filter = $this->pick([self::ALL, ...array_column($books, 'value')], $request->query('kas'), self::ALL) ?? self::ALL;
        $selected = $filter === self::ALL ? array_column($books, 'value') : [$filter];
        $organizationIds = array_values(array_filter($selected, fn (string $key): bool => $key !== self::YAYASAN_KEY));
        $status = $this->pick(array_column(ExpenseStatus::cases(), 'value'), $request->query('status'), '') ?: null;

        $result = $list->list($tenantId, $organizationIds, in_array(self::YAYASAN_KEY, $selected, true), $status, max(1, $request->integer('halaman', 1)));
        $labels = array_column($books, 'label', 'value');
        $permissions = [];
        $rows = [];

        foreach ($result['rows'] as $row) {
            $key = self::bookKey($row['organization_id']);
            $permissions[$key] ??= [
                'manage' => $this->allowsAt($row['organization_id'], FinanceAccess::CASH_MANAGE),
                'approve' => $this->allowsAt($row['organization_id'], FinanceAccess::EXPENSES_APPROVE),
            ];
            $own = $row['requested_by_id'] !== null && $row['requested_by_id'] === $membershipId;
            unset($row['requested_by_id']);

            $rows[] = $row + [
                'book' => $labels[$key] ?? '',
                'book_key' => $key,
                'is_own' => $own,
                'can_cancel' => $permissions[$key]['manage'] && $row['status'] === ExpenseStatus::Requested->value,
                'can_decide' => $permissions[$key]['approve'] && ! $own && $row['status'] === ExpenseStatus::Requested->value,
                'can_realize' => $permissions[$key]['manage'] && $row['status'] === ExpenseStatus::Approved->value,
                'can_void' => $permissions[$key]['manage'] && $row['status'] === ExpenseStatus::Realized->value,
            ];
        }

        // Rekening aktif per kas yang dikelola (untuk form realisasi).
        $manageBooks = $this->booksWith(FinanceAccess::CASH_MANAGE);
        $accounts = [];

        foreach ($manageBooks as $manageBook) {
            foreach ($book->accounts($tenantId, $manageBook['value'] === self::YAYASAN_KEY ? null : $manageBook['value']) as $account) {
                if ($account['is_active']) {
                    $accounts[$manageBook['value']][] = ['value' => $account['id'], 'label' => $account['name'], 'balance' => $account['balance']];
                }
            }
        }

        $expenseCategories = [];

        foreach ($categories->all($tenantId) as $category) {
            if ($category['direction'] === CashDirection::Out->value && $category['is_active']) {
                $expenseCategories[] = ['value' => $category['id'], 'label' => $category['name']];
            }
        }

        $requestBook = $request->query('ajukan');

        return Inertia::render('finance/expenses', [
            'books' => $books,
            'manageBooks' => $manageBooks,
            'filters' => ['kas' => $filter, 'status' => $status],
            'statuses' => array_map(fn (ExpenseStatus $case): array => ['value' => $case->value, 'label' => $case->label()], ExpenseStatus::cases()),
            'expenses' => ['rows' => $rows, 'total' => $result['total'], 'page' => $result['page'], 'last_page' => $result['last_page']],
            'accounts' => $accounts,
            'expenseCategories' => $expenseCategories,
            // Dari halaman Kas: buka form ajukan untuk kas itu.
            'openRequestFor' => is_string($requestBook) && in_array($requestBook, array_column($manageBooks, 'value'), true) ? $requestBook : null,
            'today' => BusinessClock::todayString(),
            'limits' => ['description' => ManageExpenses::DESCRIPTION_MAX_LENGTH, 'note' => ManageExpenses::NOTE_MAX_LENGTH, 'fileKb' => ExpenseFileStore::MAX_KILOBYTES],
        ]);
    }

    public function store(Request $request, ManageExpenses $expenses): RedirectResponse
    {
        $organizationId = $this->bookFromRequest($request, FinanceAccess::CASH_MANAGE);
        $request->validate(...$this->fileRule('attachment'));

        $this->attempt(fn (): string => $expenses->request(
            $this->tenantId(),
            $organizationId,
            $request->string('category_id')->toString(),
            $request->string('description')->toString(),
            $request->input('amount'),
            $request->string('needed_on')->toString(),
            $request->file('attachment'),
            $this->tenantContext->require()->membershipId,
        ));

        return $this->done('Pengeluaran diajukan. Menunggu persetujuan.');
    }

    public function cancel(string $expense, ManageExpenses $expenses): RedirectResponse
    {
        $this->authorizeExpense($expense, $expenses, FinanceAccess::CASH_MANAGE);
        $this->attempt(fn () => $expenses->cancel($this->tenantId(), $expense));

        return $this->done('Pengajuan dibatalkan.');
    }

    public function approve(Request $request, string $expense, ManageExpenses $expenses): RedirectResponse
    {
        $this->authorizeExpense($expense, $expenses, FinanceAccess::EXPENSES_APPROVE);
        $this->attempt(fn () => $expenses->approve(
            $this->tenantId(),
            $expense,
            $request->input('approved_amount'),
            $request->string('decision_note')->toString(),
            $this->tenantContext->require()->membershipId,
        ));

        return $this->done('Pengeluaran disetujui.');
    }

    public function reject(Request $request, string $expense, ManageExpenses $expenses): RedirectResponse
    {
        $this->authorizeExpense($expense, $expenses, FinanceAccess::EXPENSES_APPROVE);
        $this->attempt(fn () => $expenses->reject($this->tenantId(), $expense, $request->string('decision_note')->toString(), $this->tenantContext->require()->membershipId));

        return $this->done('Pengeluaran ditolak.');
    }

    public function realize(Request $request, string $expense, ManageExpenses $expenses): RedirectResponse
    {
        $this->authorizeExpense($expense, $expenses, FinanceAccess::CASH_MANAGE);
        $request->validate(...$this->fileRule('proof'));

        $this->attempt(fn () => $expenses->realize(
            $this->tenantId(),
            $expense,
            $request->string('account_id')->toString(),
            $request->string('entry_date')->toString(),
            $request->input('amount'),
            $request->file('proof'),
            $this->tenantContext->require()->membershipId,
        ));

        return $this->done('Pengeluaran direalisasi. Saldo kas berkurang.');
    }

    public function voidRealization(Request $request, string $expense, ManageExpenses $expenses): RedirectResponse
    {
        $this->authorizeExpense($expense, $expenses, FinanceAccess::CASH_MANAGE);
        $this->attempt(fn () => $expenses->voidRealization($this->tenantId(), $expense, $request->string('reason')->toString(), $this->tenantContext->require()->membershipId));

        return $this->done('Realisasi dibatalkan. Pengajuan kembali menunggu realisasi.');
    }

    public function file(string $expense, string $kind, ManageExpenses $expenses): BinaryFileResponse
    {
        $this->authorizeExpense($expense, $expenses, FinanceAccess::CASH_VIEW);
        $file = $expenses->file($this->tenantId(), $expense, $kind) ?? abort(404);

        return response()->download($file['path'], $file['name']);
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function authorizeExpense(string $expense, ManageExpenses $expenses, string $permission): void
    {
        $owner = $expenses->ownerOf($this->tenantId(), $expense);

        if ($owner === false) {
            abort(404);
        }

        $this->authorizeBook($owner['organization_id'], $permission);
    }

    /**
     * Aturan validasi file opsional (ukuran & jenis dicek ulang dari isi file di ExpenseFileStore).
     *
     * @return array{0: array<string, list<string>>, 1: array<string, string>}
     */
    private function fileRule(string $field): array
    {
        $megabytes = ExpenseFileStore::MAX_KILOBYTES / 1024;

        return [
            [$field => ['nullable', 'file', 'max:'.ExpenseFileStore::MAX_KILOBYTES, 'mimes:pdf,jpg,jpeg,png']],
            [
                "{$field}.max" => "Ukuran file maksimal {$megabytes} MB.",
                "{$field}.mimes" => 'File harus berupa PDF, JPG, atau PNG.',
                "{$field}.uploaded" => "File gagal diunggah. Pastikan ukurannya maksimal {$megabytes} MB.",
            ],
        ];
    }
}
