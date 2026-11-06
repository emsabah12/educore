<?php

namespace Modules\Finance\Http\Controllers\Concerns;

use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Finance\Application\Authorization\FinanceAccess;

/**
 * Lingkup halaman pembukuan (PRD-007 §7–§8): "semua" (gabungan) & "yayasan" (Yayasan pusat) hanya untuk izin
 * `finance.ledger.view` tingkat Yayasan; selain itu lembaga dalam cakupan izin. Tanpa izin sama sekali → 403.
 *
 * Kelas pemakai wajib punya properti `$authorization` (AuthorizationService).
 *
 * @property-read AuthorizationService $authorization
 */
trait WorksPerLedgerScope
{
    private const SCOPE_ALL = 'semua';

    private const SCOPE_FOUNDATION = 'yayasan';

    /**
     * [boleh lingkup Yayasan/gabungan, lembaga terlihat].
     *
     * @return array{0: bool, 1: list<array{value: string, label: string}>}
     */
    private function ledgerScopes(): array
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

    /**
     * @param  list<array{value: string, label: string}>  $organizations
     * @return list<array{value: string, label: string}>
     */
    private function ledgerScopeOptions(bool $foundation, array $organizations): array
    {
        $options = [];

        if ($foundation) {
            $options[] = ['value' => self::SCOPE_ALL, 'label' => 'Gabungan seluruh yayasan'];
            $options[] = ['value' => self::SCOPE_FOUNDATION, 'label' => 'Yayasan (pusat)'];
        }

        return [...$options, ...$organizations];
    }

    /**
     * Lingkup terpilih → saringan baris jurnal: null = gabungan, '' = Yayasan pusat, id = satu lembaga.
     */
    private function ledgerScopeFilter(?string $scope): ?string
    {
        return match ($scope) {
            null, self::SCOPE_ALL => null,
            self::SCOPE_FOUNDATION => '',
            default => $scope,
        };
    }
}
