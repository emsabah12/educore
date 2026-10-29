<?php

namespace Modules\Finance\Http\Controllers\Concerns;

use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Modules\Academic\Contracts\AcademicYears;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Finance\Application\Authorization\FinanceAccess;
use Modules\Finance\Domain\Fee\Exceptions\FinanceException;

/**
 * Bantuan bersama halaman Finance yang bekerja per lembaga × tahun ajaran (PRD-003 §5–§6):
 * pilihan lembaga sesuai cakupan, pilihan tahun ajaran, dan cek hak akses per lembaga.
 *
 * Kelas pemakai wajib punya properti `$authorization` (AuthorizationService) dan `$tenantContext` (TenantContext).
 *
 * @property-read AuthorizationService $authorization
 * @property-read TenantContext $tenantContext
 */
trait WorksPerOrganization
{
    /**
     * Lembaga aktif (node LEMBAGA) yang terlihat dengan izin lihat tagihan, urut nama.
     *
     * @return list<array{value: string, label: string}>
     */
    private function organizations(): array
    {
        $options = [];

        foreach (Organization::query()
            ->whereIn('id', $this->authorization->visibleNodeIds(FinanceAccess::BILLING_VIEW))
            ->where('type', OrganizationType::Lembaga->value)
            ->where('status', OrganizationStatus::Active->value)
            ->orderBy('name')
            ->get() as $organization) {
            $options[] = ['value' => $organization->id, 'label' => $organization->name];
        }

        return $options;
    }

    /**
     * Pilihan tahun ajaran lembaga (terbaru dulu) dan pilihan terpilih (dari URL, bawaan: berjalan).
     *
     * @return array{0: list<array{value: string, label: string, start: string, end: string}>, 1: string|null}
     */
    private function yearOptions(AcademicYears $years, ?string $organizationId, mixed $requested): array
    {
        $options = [];
        $current = null;

        foreach ($organizationId === null ? [] : $years->forOrganization($this->tenantId(), $organizationId) as $year) {
            $options[] = ['value' => $year['id'], 'label' => $year['name'].($year['is_current'] ? ' (berjalan)' : ''), 'start' => $year['start_date'], 'end' => $year['end_date']];

            if ($year['is_current']) {
                $current = $year['id'];
            }
        }

        return [$options, $this->pick(array_column($options, 'value'), $requested, $current)];
    }

    /**
     * Di luar cakupan lihat → 404 (keberadaannya tidak dibocorkan); terlihat tetapi tanpa izin $permission → 403.
     */
    private function authorizeAt(string $organizationId, string $permission): void
    {
        if (! $this->authorization->allows(FinanceAccess::BILLING_VIEW, $organizationId)) {
            abort(404);
        }

        if (! $this->authorization->allows($permission, $organizationId)) {
            abort(403);
        }
    }

    /**
     * @param  list<string>  $allowed
     */
    private function pick(array $allowed, mixed $requested, ?string $fallback = null): ?string
    {
        if (is_string($requested) && in_array($requested, $allowed, true)) {
            return $requested;
        }

        return $fallback ?? ($allowed[0] ?? null);
    }

    private function tenantId(): string
    {
        return $this->tenantContext->require()->tenantId;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    private function attempt(callable $action): mixed
    {
        try {
            return $action();
        } catch (FinanceException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
