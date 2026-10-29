<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Academic\Contracts\AcademicYears;
use Modules\Academic\Contracts\EnrolledStudents;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Finance\Application\Authorization\FinanceAccess;
use Modules\Finance\Application\Discount\DiscountOverview;
use Modules\Finance\Application\Discount\ManageDiscounts;
use Modules\Finance\Domain\Fee\Exceptions\FinanceException;
use Modules\Finance\Domain\Fee\FeeType;

/**
 * Potongan/beasiswa siswa (PRD-003 §4, FN-1b): daftar per lembaga × tahun ajaran, tambah/ubah, hapus.
 *
 * - Lihat: `finance.billing.view` di lembaga itu (Bendahara, Admin Keuangan, Admin Yayasan, Pimpinan,
 *   Kepala Lembaga).
 * - Kelola: `finance.invoices.manage` di lembaga itu (Bendahara lembaganya, Admin Keuangan, Admin Yayasan).
 * - Lembaga di luar cakupan → tidak muncul di pilihan; aksi ke lembaga di luar cakupan → 404, tanpa izin kelola → 403.
 */
final class DiscountController
{
    public const SEARCH_LIMIT = 10;

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, DiscountOverview $overview, AcademicYears $years): Response
    {
        $organizations = $this->organizations();

        if ($organizations === []) {
            abort(403);
        }

        $organizationId = $this->pick(array_column($organizations, 'value'), $request->query('lembaga'));
        $yearOptions = [];
        $current = null;

        foreach ($organizationId === null ? [] : $years->forOrganization($this->tenantId(), $organizationId) as $year) {
            $yearOptions[] = ['value' => $year['id'], 'label' => $year['name'].($year['is_current'] ? ' (berjalan)' : '')];

            if ($year['is_current']) {
                $current = $year['id'];
            }
        }

        $yearId = $this->pick(array_column($yearOptions, 'value'), $request->query('tahun'), $current);

        return Inertia::render('finance/discounts', [
            'organizations' => $organizations,
            'organizationId' => $organizationId,
            'years' => $yearOptions,
            'yearId' => $yearId,
            'discounts' => $organizationId === null || $yearId === null ? [] : $overview->list($this->tenantId(), $organizationId, $yearId),
            'canManage' => $organizationId !== null && $this->authorization->allows(FinanceAccess::INVOICES_MANAGE, $organizationId),
            'feeTypes' => FeeType::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (FeeType $type): array => ['value' => $type->id, 'label' => "{$type->name} ({$type->kind->label()})"])->values()->all(),
            'reasonMax' => ManageDiscounts::REASON_MAX_LENGTH,
        ]);
    }

    /**
     * Cari siswa aktif di lembaga (JSON) untuk form potongan. Dibatasi throttle di route.
     */
    public function searchStudents(Request $request, EnrolledStudents $students): JsonResponse
    {
        $organizationId = $request->string('lembaga')->value();
        $this->authorizeManage($organizationId);

        $query = trim($request->string('q')->value());

        if (mb_strlen($query) < 2) {
            return response()->json(['message' => 'Ketik minimal 2 huruf nama, atau awal NISN / nomor induk.', 'results' => []], 422);
        }

        return response()->json(['results' => $students->search($this->tenantId(), $organizationId, mb_substr($query, 0, 100), self::SEARCH_LIMIT)]);
    }

    public function store(Request $request, ManageDiscounts $discounts): RedirectResponse
    {
        $request->validate([
            'organization_id' => ['required', 'uuid'],
            'academic_year_id' => ['required', 'uuid'],
            'enrollment_id' => ['required', 'uuid'],
            'fee_type_id' => ['required', 'uuid'],
            'kind' => ['required', Rule::in([ManageDiscounts::KIND_PERCENT, ManageDiscounts::KIND_AMOUNT])],
            'value' => ['required', 'string', 'max:30'],
            'reason' => ['required', 'string', 'max:'.ManageDiscounts::REASON_MAX_LENGTH],
        ], [
            'enrollment_id.required' => 'Cari lalu pilih siswanya.',
            'fee_type_id.required' => 'Pilih jenis biaya.',
            'kind.required' => 'Pilih jenis potongan: persen atau nominal.',
            'value.required' => 'Isi besar potongan.',
            'reason.required' => 'Alasan potongan wajib diisi.',
        ], ['reason' => 'alasan', 'value' => 'besar potongan']);

        $organizationId = $request->string('organization_id')->value();
        $this->authorizeManage($organizationId);

        $created = $this->attempt(fn (): bool => $discounts->set(
            $this->tenantId(),
            $organizationId,
            $request->string('enrollment_id')->value(),
            $request->string('fee_type_id')->value(),
            $request->string('academic_year_id')->value(),
            $request->string('kind')->value(),
            $request->input('value'),
            $request->string('reason')->value(),
            $this->tenantContext->require()->membershipId,
        ));

        return $this->done($created ? 'Potongan ditambahkan. Berlaku untuk tagihan yang dibuat sesudah ini.' : 'Potongan diubah. Tagihan yang sudah dibuat tidak berubah.');
    }

    public function destroy(string $discount, ManageDiscounts $discounts): RedirectResponse
    {
        $organizationId = $discounts->organizationOf($this->tenantId(), $discount);

        if ($organizationId === null) {
            abort(404);
        }

        $this->authorizeManage($organizationId);
        $this->attempt(function () use ($discounts, $discount): bool {
            $discounts->remove($this->tenantId(), $discount);

            return true;
        });

        return $this->done('Potongan dihapus. Tagihan yang sudah dibuat tidak berubah.');
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

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
     * Di luar cakupan lihat → 404 (keberadaannya tidak dibocorkan); terlihat tetapi tanpa izin kelola → 403.
     */
    private function authorizeManage(string $organizationId): void
    {
        if (! $this->authorization->allows(FinanceAccess::BILLING_VIEW, $organizationId)) {
            abort(404);
        }

        if (! $this->authorization->allows(FinanceAccess::INVOICES_MANAGE, $organizationId)) {
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
