<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Finance\Application\Authorization\FinanceAccess;
use Modules\Finance\Application\Ledger\ChartOfAccounts;
use Modules\Finance\Application\Ledger\JournalProjector;
use Modules\Finance\Application\Ledger\LedgerSetup;
use Modules\Finance\Application\Ledger\OpeningItems;
use Modules\Finance\Domain\Ledger\AccountGroup;
use Modules\Finance\Domain\Ledger\CashFlowActivity;
use Modules\Finance\Http\Controllers\Concerns\WorksPerOrganization;

/**
 * Pengaturan pembukuan (PRD-007 §3, §5, §9; PB-1): tab Akun (COA), Pemetaan, Saldo awal (PB-2a), Periode
 * (tanggal mulai, tahun buku, susun ulang jurnal). Hanya `finance.ledger.manage` tingkat Yayasan; tanpa izin 403.
 */
final class LedgerSettingsController
{
    use WorksPerOrganization;

    private const FOUNDATION = 'yayasan';

    private const MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, ChartOfAccounts $accounts, LedgerSetup $setup, OpeningItems $items, JournalProjector $projector): Response
    {
        $this->authorize();
        $tenantId = $this->tenantId();
        $accounts->ensureDefaults($tenantId);

        // Tab Saldo awal (PB-2a): Kas Yayasan + tiap lembaga aktif.
        $openingScopes = [['value' => self::FOUNDATION, 'label' => 'Kas Yayasan']];

        foreach (Organization::query()
            ->where('type', OrganizationType::Lembaga->value)
            ->where('status', OrganizationStatus::Active->value)
            ->orderBy('name')
            ->get() as $organization) {
            $openingScopes[] = ['value' => (string) $organization->id, 'label' => (string) $organization->name];
        }

        $openingScope = $this->pick(array_column($openingScopes, 'value'), $request->query('lingkup'), self::FOUNDATION) ?? self::FOUNDATION;
        $openingOrganization = $openingScope === self::FOUNDATION ? null : $openingScope;
        $preview = $projector->openingPreview($tenantId, $openingOrganization);
        $cashNames = DB::table('fn_cash_accounts')
            ->whereIn('id', array_values(array_filter(array_column($preview['lines'] ?? [], 'cash_account_id'))))
            ->pluck('name', 'id');

        return Inertia::render('finance/ledger-settings', [
            'settings' => $setup->settings($tenantId),
            'accounts' => $accounts->all($tenantId),
            'groups' => array_map(fn (AccountGroup $group): array => ['value' => $group->value, 'label' => $group->label(), 'prefix' => $group->codePrefix(), 'normal' => $group->normalBalance()->value], AccountGroup::cases()),
            'activities' => array_map(fn (CashFlowActivity $activity): array => ['value' => $activity->value, 'label' => $activity->label()], CashFlowActivity::cases()),
            'mappings' => $setup->mappings($tenantId),
            'targets' => $setup->targets($tenantId),
            'months' => array_map(fn (int $index, string $name): array => ['value' => (string) ($index + 1), 'label' => $name], array_keys(self::MONTHS), self::MONTHS),
            'nameMax' => ChartOfAccounts::NAME_MAX_LENGTH,
            'openingScopes' => $openingScopes,
            'openingScope' => $openingScope,
            'opening' => [
                'description' => $preview['description'] ?? null,
                'lines' => array_map(fn (array $line): array => [
                    'account_id' => $line['account_id'],
                    'cash_account' => $line['cash_account_id'] === null ? null : (string) ($cashNames[$line['cash_account_id']] ?? ''),
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                ], $preview['lines'] ?? []),
                'items' => $items->all($tenantId, $openingOrganization),
            ],
            'openingAccounts' => $accounts->manualOptions($tenantId, true),
            'noteMax' => OpeningItems::NOTE_MAX_LENGTH,
        ]);
    }

    public function storeAccount(Request $request, ChartOfAccounts $accounts): RedirectResponse
    {
        $this->authorize();
        $group = AccountGroup::tryFrom($request->string('group')->toString());

        if ($group === null) {
            return back()->withErrors(['group' => 'Pilih golongan akun.']);
        }

        $this->attempt(fn (): string => $accounts->create($this->tenantId(), [
            'code' => $request->string('code')->toString(),
            'name' => $request->string('name')->toString(),
            'group' => $group,
            'is_header' => $request->boolean('is_header'),
            'parent_id' => $request->filled('parent_id') ? $request->string('parent_id')->toString() : null,
            'contra' => $request->boolean('contra'),
            'is_cash' => $request->boolean('is_cash'),
            'is_restricted' => $request->boolean('is_restricted'),
            'cash_flow_activity' => CashFlowActivity::tryFrom($request->string('cash_flow_activity')->toString()),
        ]));

        return $this->done('Akun ditambahkan.');
    }

    public function updateAccount(Request $request, string $account, ChartOfAccounts $accounts): RedirectResponse
    {
        $this->authorize();
        $this->attempt(fn () => $accounts->update($this->tenantId(), $account, [
            'name' => $request->string('name')->toString(),
            'parent_id' => $request->filled('parent_id') ? $request->string('parent_id')->toString() : null,
            'is_restricted' => $request->boolean('is_restricted'),
            'cash_flow_activity' => CashFlowActivity::tryFrom($request->string('cash_flow_activity')->toString()),
        ]));

        return $this->done('Akun disimpan.');
    }

    public function deactivateAccount(string $account, ChartOfAccounts $accounts): RedirectResponse
    {
        $this->authorize();
        $this->attempt(fn () => $accounts->setActive($this->tenantId(), $account, false));

        return $this->done('Akun dinonaktifkan.');
    }

    public function activateAccount(string $account, ChartOfAccounts $accounts): RedirectResponse
    {
        $this->authorize();
        $this->attempt(fn () => $accounts->setActive($this->tenantId(), $account, true));

        return $this->done('Akun diaktifkan kembali.');
    }

    public function setMapping(Request $request, LedgerSetup $setup): RedirectResponse
    {
        $this->authorize();
        $this->attempt(fn () => $setup->setMapping(
            $this->tenantId(),
            $request->string('kind')->toString(),
            $request->string('item_id')->toString(),
            $request->filled('account_id') ? $request->string('account_id')->toString() : null,
        ));

        return $this->done('Pemetaan disimpan.');
    }

    public function setPeriod(Request $request, LedgerSetup $setup): RedirectResponse
    {
        $this->authorize();
        $this->attempt(fn () => $setup->setPeriod($this->tenantId(), $request->string('start_date')->toString(), $request->integer('fiscal_start_month')));

        return $this->done('Pengaturan periode disimpan.');
    }

    /**
     * Satu langkah susun ulang (±5 detik). Halaman memanggil lagi sampai selesai; `mulai=1` memaksa susun ulang dari
     * awal walaupun pengaturan tidak berubah.
     */
    public function rebuild(Request $request, LedgerSetup $setup, JournalProjector $projector): RedirectResponse
    {
        $this->authorize();
        $tenantId = $this->tenantId();
        $settings = $setup->settings($tenantId);

        if ($request->boolean('mulai') && ! $settings['rebuilding'] && ! $settings['needs_rebuild']) {
            $this->attempt(fn () => $setup->requestRebuild($tenantId));
        }

        $state = $this->attempt(fn (): array => $projector->rebuildStep($tenantId));

        if (! $state['finished']) {
            return back();
        }

        return $this->done('Jurnal otomatis selesai disusun.');
    }

    public function storeOpening(Request $request, OpeningItems $items): RedirectResponse
    {
        $this->authorize();
        $this->attempt(fn (): string => $items->add(
            $this->tenantId(),
            $this->tenantContext->require()->membershipId,
            $request->string('lingkup')->toString(),
            $request->string('account_id')->toString(),
            $request->string('side')->toString(),
            $request->input('amount'),
            $request->string('note')->toString(),
        ));

        return $this->done('Baris saldo awal ditambahkan.');
    }

    public function updateOpening(Request $request, string $item, OpeningItems $items): RedirectResponse
    {
        $this->authorize();
        $this->attempt(fn () => $items->update(
            $this->tenantId(),
            $item,
            $request->string('account_id')->toString(),
            $request->string('side')->toString(),
            $request->input('amount'),
            $request->string('note')->toString(),
        ));

        return $this->done('Baris saldo awal disimpan.');
    }

    public function destroyOpening(string $item, OpeningItems $items): RedirectResponse
    {
        $this->authorize();
        $this->attempt(fn () => $items->remove($this->tenantId(), $item));

        return $this->done('Baris saldo awal dihapus.');
    }

    private function authorize(): void
    {
        $this->authorization->inspectTarget(FinanceAccess::LEDGER_MANAGE, null, null)->authorize();
    }
}
