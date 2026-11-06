<?php

namespace Modules\Finance\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Authorization\FinanceAccess;
use Modules\Finance\Application\Ledger\ChartOfAccounts;
use Modules\Finance\Application\Ledger\JournalList;
use Modules\Finance\Application\Ledger\JournalProjector;
use Modules\Finance\Application\Ledger\LedgerSetup;
use Modules\Finance\Application\Ledger\ManualJournals;
use Modules\Finance\Domain\Ledger\JournalSource;
use Modules\Finance\Domain\Payment\PaymentStatus;
use Modules\Finance\Http\Controllers\Concerns\WorksPerLedgerScope;
use Modules\Finance\Http\Controllers\Concerns\WorksPerOrganization;

/**
 * Daftar & rincian jurnal (PRD-007 §7, §9; PB-1) + jurnal manual (§6, PB-2a), untuk pemilik `finance.ledger.view`.
 *
 * Lingkup (`?lingkup=`): "semua" (gabungan) & "yayasan" (Yayasan pusat) hanya untuk izin tingkat Yayasan; selain itu
 * id lembaga dalam cakupan izin. Antrean jurnal otomatis diproses dulu sebelum halaman tampil.
 */
final class JournalController
{
    use WorksPerLedgerScope;
    use WorksPerOrganization;

    private const MONTH_NAMES = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, JournalList $list, JournalProjector $projector, LedgerSetup $setup, ManualJournals $manual, ChartOfAccounts $accounts): Response
    {
        [$foundation, $organizations] = $this->ledgerScopes();
        $tenantId = $this->tenantId();
        $settings = $setup->settings($tenantId);
        $pending = $settings['active'] ? $projector->processQueue($tenantId) : 0;

        $scopeOptions = $this->ledgerScopeOptions($foundation, $organizations);
        $scope = $this->pick(array_column($scopeOptions, 'value'), $request->query('lingkup'));
        $months = $list->months($tenantId);
        $month = $this->pick($months, $request->query('bulan'), '') ?: null;
        $source = JournalSource::tryFrom($request->string('sumber')->toString());
        $status = in_array($request->query('status'), [PaymentStatus::Valid->value, PaymentStatus::Void->value], true) ? (string) $request->query('status') : null;
        $search = mb_substr($request->string('cari')->trim()->toString(), 0, JournalList::SEARCH_MAX_LENGTH);

        $canManage = $this->authorization->inspectTarget(FinanceAccess::LEDGER_MANAGE, null, null)->allowed();
        $range = $canManage ? $manual->dateRange($tenantId) : null;

        return Inertia::render('finance/journals', [
            'settings' => $settings + ['pending' => $pending],
            'canManage' => $canManage,
            // Formulir jurnal manual (PB-2a): akun yang boleh dipakai, lembaga baris (Yayasan + semua lembaga aktif), rentang tanggal.
            'manual' => $range === null ? null : [
                'accounts' => $accounts->manualOptions($tenantId, false),
                'organizations' => [['value' => '', 'label' => 'Yayasan'], ...$this->allOrganizations()],
                'min_date' => $range[0],
                'max_date' => $range[1],
                'today' => min(max(BusinessClock::todayString(), $range[0]), $range[1]),
                'max_lines' => ManualJournals::MAX_LINES,
                'description_max' => ManualJournals::DESCRIPTION_MAX_LENGTH,
            ],
            'scopes' => $scopeOptions,
            'scope' => $scope,
            'months' => array_map(fn (string $value): array => ['value' => $value, 'label' => $this->monthLabel($value)], $months),
            'month' => $month,
            'sources' => array_map(fn (JournalSource $item): array => ['value' => $item->value, 'label' => $item->label()], JournalSource::cases()),
            'source' => $source?->value,
            'status' => $status,
            'search' => $search,
            'journals' => $list->page(
                $tenantId,
                $this->ledgerScopeFilter($scope),
                $month,
                $source,
                $status,
                $search,
                max(1, $request->integer('halaman', 1)),
            ),
            'searchMax' => JournalList::SEARCH_MAX_LENGTH,
        ]);
    }

    public function show(string $journal, JournalList $list, JournalProjector $projector, LedgerSetup $setup): Response
    {
        [$foundation, $organizations] = $this->ledgerScopes();
        $tenantId = $this->tenantId();

        if ($setup->settings($tenantId)['active']) {
            $projector->processQueue($tenantId);
        }

        $found = $list->find($tenantId, $journal, $foundation ? null : array_column($organizations, 'value'));

        if ($found === null) {
            abort(404);
        }

        $canVoid = $found['journal']['source'] === JournalSource::Manual->value
            && $found['journal']['status'] === PaymentStatus::Valid->value
            && $this->authorization->inspectTarget(FinanceAccess::LEDGER_MANAGE, null, null)->allowed();

        return Inertia::render('finance/journal-show', $found + ['canVoid' => $canVoid, 'reasonMax' => ManualJournals::REASON_MAX_LENGTH]);
    }

    /**
     * Catat jurnal manual (PRD-007 §6, PB-2a). Hanya `finance.ledger.manage` tingkat Yayasan.
     */
    public function store(Request $request, ManualJournals $manual): RedirectResponse
    {
        $this->authorization->inspectTarget(FinanceAccess::LEDGER_MANAGE, null, null)->authorize();

        $id = $this->attempt(fn (): string => $manual->post(
            $this->tenantId(),
            $this->tenantContext->require()->membershipId,
            $request->string('date')->toString(),
            $request->string('description')->toString(),
            $request->input('lines'),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Jurnal manual dicatat.']);

        return redirect('/keuangan/pembukuan/jurnal/'.$id);
    }

    public function void(Request $request, string $journal, ManualJournals $manual): RedirectResponse
    {
        $this->authorization->inspectTarget(FinanceAccess::LEDGER_MANAGE, null, null)->authorize();
        $this->attempt(fn () => $manual->void($this->tenantId(), $journal, $request->string('reason')->toString()));

        return $this->done('Jurnal manual dibatalkan.');
    }

    /**
     * Semua lembaga aktif yayasan (untuk baris jurnal manual), urut nama.
     *
     * @return list<array{value: string, label: string}>
     */
    private function allOrganizations(): array
    {
        $options = [];

        foreach (Organization::query()
            ->where('type', OrganizationType::Lembaga->value)
            ->where('status', OrganizationStatus::Active->value)
            ->orderBy('name')
            ->get() as $organization) {
            $options[] = ['value' => (string) $organization->id, 'label' => (string) $organization->name];
        }

        return $options;
    }

    private function monthLabel(string $month): string
    {
        $date = CarbonImmutable::createFromFormat('!Y-m', $month, BusinessClock::timezone());

        return $date === null ? $month : self::MONTH_NAMES[$date->month - 1].' '.$date->year;
    }
}
