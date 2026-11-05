<?php

namespace Modules\Finance\Http\Controllers;

use Carbon\CarbonImmutable;
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
use Modules\Finance\Application\Ledger\JournalList;
use Modules\Finance\Application\Ledger\JournalProjector;
use Modules\Finance\Application\Ledger\LedgerSetup;
use Modules\Finance\Domain\Ledger\JournalSource;
use Modules\Finance\Domain\Payment\PaymentStatus;
use Modules\Finance\Http\Controllers\Concerns\WorksPerOrganization;

/**
 * Daftar & rincian jurnal (PRD-007 §7, §9; PB-1), untuk pemilik `finance.ledger.view`.
 *
 * Lingkup (`?lingkup=`): "semua" (gabungan) & "yayasan" (Yayasan pusat) hanya untuk izin tingkat Yayasan; selain itu
 * id lembaga dalam cakupan izin. Antrean jurnal otomatis diproses dulu sebelum halaman tampil.
 */
final class JournalController
{
    use WorksPerOrganization;

    private const SCOPE_ALL = 'semua';

    private const SCOPE_FOUNDATION = 'yayasan';

    private const MONTH_NAMES = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, JournalList $list, JournalProjector $projector, LedgerSetup $setup): Response
    {
        [$foundation, $organizations] = $this->scopes();
        $tenantId = $this->tenantId();
        $settings = $setup->settings($tenantId);
        $pending = $settings['active'] ? $projector->processQueue($tenantId) : 0;

        $scopeOptions = [];

        if ($foundation) {
            $scopeOptions[] = ['value' => self::SCOPE_ALL, 'label' => 'Gabungan seluruh yayasan'];
            $scopeOptions[] = ['value' => self::SCOPE_FOUNDATION, 'label' => 'Yayasan (pusat)'];
        }

        $scopeOptions = [...$scopeOptions, ...$organizations];
        $scope = $this->pick(array_column($scopeOptions, 'value'), $request->query('lingkup'));
        $months = $list->months($tenantId);
        $month = $this->pick($months, $request->query('bulan'), '') ?: null;
        $source = JournalSource::tryFrom($request->string('sumber')->toString());
        $status = in_array($request->query('status'), [PaymentStatus::Valid->value, PaymentStatus::Void->value], true) ? (string) $request->query('status') : null;
        $search = mb_substr($request->string('cari')->trim()->toString(), 0, JournalList::SEARCH_MAX_LENGTH);

        return Inertia::render('finance/journals', [
            'settings' => $settings + ['pending' => $pending],
            'canManage' => $this->authorization->inspectTarget(FinanceAccess::LEDGER_MANAGE, null, null)->allowed(),
            'scopes' => $scopeOptions,
            'scope' => $scope,
            'months' => array_map(fn (string $value): array => ['value' => $value, 'label' => $this->monthLabel($value)], $months),
            'month' => $month,
            'sources' => array_map(fn (JournalSource $item): array => ['value' => $item->value, 'label' => $item->label()], JournalSource::automatic()),
            'source' => $source?->value,
            'status' => $status,
            'search' => $search,
            'journals' => $list->page(
                $tenantId,
                match ($scope) {
                    null, self::SCOPE_ALL => null,
                    self::SCOPE_FOUNDATION => '',
                    default => $scope,
                },
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
        [$foundation, $organizations] = $this->scopes();
        $tenantId = $this->tenantId();

        if ($setup->settings($tenantId)['active']) {
            $projector->processQueue($tenantId);
        }

        $found = $list->find($tenantId, $journal, $foundation ? null : array_column($organizations, 'value'));

        if ($found === null) {
            abort(404);
        }

        return Inertia::render('finance/journal-show', $found);
    }

    /**
     * [boleh lingkup Yayasan/gabungan, lembaga terlihat]; tanpa izin sama sekali → 403.
     *
     * @return array{0: bool, 1: list<array{value: string, label: string}>}
     */
    private function scopes(): array
    {
        $foundation = $this->authorization->inspectTarget(FinanceAccess::LEDGER_VIEW, null, null)->allowed();
        $organizations = [];

        foreach (Organization::query()
            ->whereIn('id', $this->authorization->visibleNodeIds(FinanceAccess::LEDGER_VIEW))
            ->where('type', OrganizationType::Lembaga->value)
            ->where('status', OrganizationStatus::Active->value)
            ->orderBy('name')
            ->get() as $organization) {
            $organizations[] = ['value' => (string) $organization->id, 'label' => (string) $organization->name];
        }

        if (! $foundation && $organizations === []) {
            abort(403);
        }

        return [$foundation, $organizations];
    }

    private function monthLabel(string $month): string
    {
        $date = CarbonImmutable::createFromFormat('!Y-m', $month, BusinessClock::timezone());

        return $date === null ? $month : self::MONTH_NAMES[$date->month - 1].' '.$date->year;
    }
}
