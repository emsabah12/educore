<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Finance\Application\Authorization\FinanceAccess;
use Modules\Finance\Application\Ledger\ChartOfAccounts;
use Modules\Finance\Application\Ledger\JournalProjector;
use Modules\Finance\Application\Ledger\LedgerSetup;
use Modules\Finance\Domain\Ledger\AccountGroup;
use Modules\Finance\Domain\Ledger\CashFlowActivity;
use Modules\Finance\Http\Controllers\Concerns\WorksPerOrganization;

/**
 * Pengaturan pembukuan (PRD-007 §3, §5, §9; PB-1): tab Akun (COA), Pemetaan, Periode (tanggal mulai, tahun buku,
 * susun ulang jurnal). Hanya `finance.ledger.manage` tingkat Yayasan; tanpa izin 403.
 */
final class LedgerSettingsController
{
    use WorksPerOrganization;

    private const MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(ChartOfAccounts $accounts, LedgerSetup $setup): Response
    {
        $this->authorize();
        $tenantId = $this->tenantId();
        $accounts->ensureDefaults($tenantId);

        return Inertia::render('finance/ledger-settings', [
            'settings' => $setup->settings($tenantId),
            'accounts' => $accounts->all($tenantId),
            'groups' => array_map(fn (AccountGroup $group): array => ['value' => $group->value, 'label' => $group->label(), 'prefix' => $group->codePrefix(), 'normal' => $group->normalBalance()->value], AccountGroup::cases()),
            'activities' => array_map(fn (CashFlowActivity $activity): array => ['value' => $activity->value, 'label' => $activity->label()], CashFlowActivity::cases()),
            'mappings' => $setup->mappings($tenantId),
            'targets' => $setup->targets($tenantId),
            'months' => array_map(fn (int $index, string $name): array => ['value' => (string) ($index + 1), 'label' => $name], array_keys(self::MONTHS), self::MONTHS),
            'nameMax' => ChartOfAccounts::NAME_MAX_LENGTH,
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

    private function authorize(): void
    {
        $this->authorization->inspectTarget(FinanceAccess::LEDGER_MANAGE, null, null)->authorize();
    }
}
