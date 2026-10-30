<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Finance\Application\Authorization\FinanceAccess;
use Modules\Finance\Application\Cash\ManageCashCategories;
use Modules\Finance\Domain\Cash\CashDirection;
use Modules\Finance\Http\Controllers\Concerns\WorksPerOrganization;

/**
 * Kategori kas (PRD-004 §3, §6, KS-1). Hanya `finance.settings.manage` tingkat Yayasan (Admin Keuangan,
 * Admin Yayasan); tanpa izin 403.
 */
final class CashCategoryController
{
    use WorksPerOrganization;

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(ManageCashCategories $categories): Response
    {
        $this->authorize();
        $categories->ensureDefaults($this->tenantId());

        return Inertia::render('finance/cash-categories', [
            'categories' => $categories->all($this->tenantId()),
            'directions' => array_map(fn (CashDirection $direction): array => ['value' => $direction->value, 'label' => $direction->label()], CashDirection::cases()),
            'nameMax' => ManageCashCategories::NAME_MAX_LENGTH,
        ]);
    }

    public function store(Request $request, ManageCashCategories $categories): RedirectResponse
    {
        $this->authorize();
        $direction = CashDirection::tryFrom($request->string('direction')->toString());

        if ($direction === null) {
            return back()->withErrors(['direction' => 'Pilih jenis kategori: pemasukan atau pengeluaran.']);
        }

        $this->attempt(fn (): string => $categories->create($this->tenantId(), $request->string('code')->toString(), $request->string('name')->toString(), $direction));

        return $this->done('Kategori ditambahkan.');
    }

    public function update(Request $request, string $category, ManageCashCategories $categories): RedirectResponse
    {
        $this->authorize();
        $this->attempt(fn () => $categories->rename($this->tenantId(), $category, $request->string('name')->toString()));

        return $this->done('Kategori disimpan.');
    }

    public function deactivate(string $category, ManageCashCategories $categories): RedirectResponse
    {
        $this->authorize();
        $this->attempt(fn () => $categories->setActive($this->tenantId(), $category, false));

        return $this->done('Kategori dinonaktifkan.');
    }

    public function activate(string $category, ManageCashCategories $categories): RedirectResponse
    {
        $this->authorize();
        $this->attempt(fn () => $categories->setActive($this->tenantId(), $category, true));

        return $this->done('Kategori diaktifkan kembali.');
    }

    private function authorize(): void
    {
        $this->authorization->inspectTarget(FinanceAccess::SETTINGS_MANAGE, null, null)->authorize();
    }
}
