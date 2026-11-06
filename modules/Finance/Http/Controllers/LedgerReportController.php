<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Finance\Application\Authorization\FinanceAccess;
use Modules\Finance\Application\Ledger\ChartOfAccounts;
use Modules\Finance\Application\Ledger\JournalProjector;
use Modules\Finance\Application\Ledger\LedgerExport;
use Modules\Finance\Application\Ledger\LedgerReports;
use Modules\Finance\Application\Ledger\LedgerSetup;
use Modules\Finance\Http\Controllers\Concerns\WorksPerLedgerScope;
use Modules\Finance\Http\Controllers\Concerns\WorksPerOrganization;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Neraca saldo, buku besar, cek kecocokan (PRD-007 §7, §9; PB-2b) untuk pemilik `finance.ledger.view`, sesuai lingkup.
 * Antrean jurnal otomatis diproses dulu supaya angka mutakhir.
 *
 * @phpstan-import-type Period from LedgerReports
 */
final class LedgerReportController
{
    use WorksPerLedgerScope;
    use WorksPerOrganization;

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function trialBalance(Request $request, LedgerReports $reports, LedgerSetup $setup, JournalProjector $projector): Response
    {
        $view = $this->prepare($request, $reports, $setup, $projector);

        return Inertia::render('finance/trial-balance', $view['props'] + [
            'report' => $view['period'] === null ? null : $reports->trialBalance($this->tenantId(), $view['filter'], $view['period']),
        ]);
    }

    public function exportTrialBalance(Request $request, LedgerReports $reports, LedgerSetup $setup, JournalProjector $projector, LedgerExport $export): BinaryFileResponse
    {
        $view = $this->prepare($request, $reports, $setup, $projector);
        $period = $view['period'] ?? abort(404);
        $path = $this->tempFile();
        $export->trialBalance($this->tenantId(), $view['filter'], $period, "Neraca saldo — {$view['scope_label']}", $path);

        return $this->download($path, LedgerExport::fileName('neraca-saldo', (string) $view['props']['scope'], $period['from'], $period['to']));
    }

    public function generalLedger(Request $request, LedgerReports $reports, LedgerSetup $setup, JournalProjector $projector, ChartOfAccounts $accounts): Response
    {
        $view = $this->prepare($request, $reports, $setup, $projector);
        $tenantId = $this->tenantId();
        $options = $reports->accountOptions($tenantId);
        $account = $this->pick(array_column($options, 'value'), $request->query('akun'), $accounts->keyed($tenantId)[ChartOfAccounts::KEY_CASH] ?? null);

        return Inertia::render('finance/general-ledger', $view['props'] + [
            'accounts' => $options,
            'account' => $account,
            'ledger' => $view['period'] === null || $account === null
                ? null
                : $reports->ledger($tenantId, $account, $view['filter'], $view['period'], max(1, $request->integer('halaman', 1))),
        ]);
    }

    public function exportGeneralLedger(Request $request, LedgerReports $reports, LedgerSetup $setup, JournalProjector $projector, LedgerExport $export): BinaryFileResponse
    {
        $view = $this->prepare($request, $reports, $setup, $projector);
        $period = $view['period'] ?? abort(404);
        $ledger = $reports->ledger($this->tenantId(), $request->string('akun')->toString(), $view['filter'], $period, 1) ?? abort(404);
        $path = $this->tempFile();
        $export->ledger($this->tenantId(), $view['filter'], $period, $ledger, "Buku besar {$ledger['account']['code']} {$ledger['account']['name']} — {$view['scope_label']}", $path);

        return $this->download($path, LedgerExport::fileName('buku-besar-'.$ledger['account']['code'], (string) $view['props']['scope'], $period['from'], $period['to']));
    }

    public function reconciliation(LedgerReports $reports, LedgerSetup $setup, JournalProjector $projector): Response
    {
        [$foundation, $organizations] = $this->ledgerScopes();
        $tenantId = $this->tenantId();
        $settings = $setup->settings($tenantId);
        $pending = $settings['active'] ? $projector->processQueue($tenantId) : 0;
        $rows = $settings['active'] ? $reports->reconciliation($tenantId, $foundation ? null : array_column($organizations, 'value')) : [];

        return Inertia::render('finance/ledger-reconciliation', [
            'settings' => $settings + ['pending' => $pending],
            'canManage' => $this->authorization->inspectTarget(FinanceAccess::LEDGER_MANAGE, null, null)->allowed(),
            'rows' => $rows,
            'mismatches' => count(array_filter($rows, fn (array $row): bool => $row['difference'] !== 0)),
        ]);
    }

    /**
     * Lingkup, periode, dan props bersama halaman laporan.
     *
     * @return array{props: array<string, mixed>, filter: string|null, period: Period|null, scope_label: string}
     */
    private function prepare(Request $request, LedgerReports $reports, LedgerSetup $setup, JournalProjector $projector): array
    {
        [$foundation, $organizations] = $this->ledgerScopes();
        $tenantId = $this->tenantId();
        $settings = $setup->settings($tenantId);
        $pending = $settings['active'] ? $projector->processQueue($tenantId) : 0;
        $options = $this->ledgerScopeOptions($foundation, $organizations);
        $scope = $this->pick(array_column($options, 'value'), $request->query('lingkup'));
        $period = $settings['active'] ? $reports->period($tenantId, $request->query('dari'), $request->query('sampai')) : null;
        $label = '';

        foreach ($options as $option) {
            if ($option['value'] === $scope) {
                $label = $option['label'];
            }
        }

        return [
            'props' => [
                'settings' => $settings + ['pending' => $pending],
                'scopes' => $options,
                'scope' => $scope,
                'period' => $period,
                'presets' => $period === null ? [] : $reports->presets($period, $reports->fiscalMonth($tenantId)),
            ],
            'filter' => $this->ledgerScopeFilter($scope),
            'period' => $period,
            'scope_label' => $label,
        ];
    }

    private function tempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'educore-pembukuan-');

        if ($path === false) {
            abort(500, 'Laporan tidak bisa dibuat.');
        }

        return $path;
    }

    private function download(string $path, string $name): BinaryFileResponse
    {
        return response()
            ->download($path, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
            ->deleteFileAfterSend();
    }
}
