<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Academic\Contracts\AcademicYears;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Authorization\FinanceAccess;
use Modules\Finance\Application\Fee\Rupiah;
use Modules\Finance\Application\Invoice\GenerateMonthlyInvoices;
use Modules\Finance\Application\Invoice\InvoiceList;
use Modules\Finance\Application\Invoice\ManageInvoices;
use Modules\Finance\Domain\Fee\FeeKind;
use Modules\Finance\Domain\Fee\FeeType;
use Modules\Finance\Domain\Invoice\InvoiceStatus;
use Modules\Finance\Http\Controllers\Concerns\WorksPerOrganization;

/**
 * Tagihan siswa (PRD-003 §4, §6, FN-2a): daftar per lembaga × tahun ajaran, buat tagihan bulanan
 * (pratinjau lalu buat), batalkan tagihan.
 *
 * - Lihat: `finance.billing.view` di lembaga itu.
 * - Buat & batalkan: `finance.invoices.manage` di lembaga itu (Bendahara lembaganya, Admin Keuangan,
 *   Admin Yayasan). Lembaga di luar cakupan → 404; tanpa izin kelola → 403.
 */
final class InvoiceController
{
    use WorksPerOrganization;

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, AcademicYears $years, InvoiceList $list): Response
    {
        $organizations = $this->organizations();

        if ($organizations === []) {
            abort(403);
        }

        $organizationId = $this->pick(array_column($organizations, 'value'), $request->query('lembaga'));
        [$yearOptions, $yearId] = $this->yearOptions($years, $organizationId, $request->query('tahun'));
        $year = null;

        foreach ($yearOptions as $option) {
            if ($option['value'] === $yearId) {
                $year = $option;
            }
        }

        $types = FeeType::query()->orderByDesc('is_active')->orderBy('name')->get();
        $typeIds = [];

        foreach ($types as $type) {
            $typeIds[] = $type->id;
        }

        $months = $year === null ? [] : GenerateMonthlyInvoices::monthOptions($year['start'], $year['end']);
        $filters = [
            'type' => $this->pick($typeIds, $request->query('jenis'), '') ?: null,
            'month' => $this->pick(array_column($months, 'value'), $request->query('bulan'), '') ?: null,
            'status' => $this->pick(array_column(InvoiceStatus::cases(), 'value'), $request->query('status'), '') ?: null,
            'q' => $this->query($request->query('q')),
        ];

        return Inertia::render('finance/invoices', [
            'organizations' => $organizations,
            'organizationId' => $organizationId,
            'years' => array_map(fn (array $option): array => ['value' => $option['value'], 'label' => $option['label']], $yearOptions),
            'yearId' => $yearId,
            'filters' => $filters,
            'invoices' => $organizationId === null || $yearId === null
                ? null
                : $list->list($this->tenantId(), $organizationId, $yearId, $filters, max(1, $request->integer('halaman', 1)), BusinessClock::todayString()),
            'options' => [
                'types' => $types->map(fn (FeeType $type): array => ['value' => $type->id, 'label' => $type->name.($type->is_active ? '' : ' (nonaktif)')])->values()->all(),
                'monthlyTypes' => $types->filter(fn (FeeType $type): bool => $type->is_active && $type->kind === FeeKind::Monthly)
                    ->map(fn (FeeType $type): array => ['value' => $type->id, 'label' => $type->name])->values()->all(),
                'months' => $months,
                'statuses' => array_map(fn (InvoiceStatus $status): array => ['value' => $status->value, 'label' => $status->label()], InvoiceStatus::cases()),
            ],
            'canManage' => $organizationId !== null && $this->authorization->allows(FinanceAccess::INVOICES_MANAGE, $organizationId),
            'voidReasonMax' => ManageInvoices::REASON_MAX_LENGTH,
        ]);
    }

    /**
     * Pratinjau tagihan bulanan (JSON, tidak menyimpan apa pun). Dibatasi throttle di route.
     */
    public function preview(Request $request, GenerateMonthlyInvoices $generator): JsonResponse
    {
        $input = $this->generateInput($request);

        $summary = $this->attempt(fn (): array => $generator->plan($this->tenantId(), $input['organization_id'], $input['academic_year_id'], $input['fee_type_id'], $input['from'], $input['to']));

        return response()->json(['summary' => $summary + ['total_label' => Rupiah::format($summary['total'])]]);
    }

    public function generate(Request $request, GenerateMonthlyInvoices $generator): RedirectResponse
    {
        $input = $this->generateInput($request);

        $summary = $this->attempt(fn (): array => $generator->generate(
            $this->tenantId(),
            $input['organization_id'],
            $input['academic_year_id'],
            $input['fee_type_id'],
            $input['from'],
            $input['to'],
            $this->tenantContext->require()->membershipId,
        ));

        $message = $summary['created'] === 0
            ? 'Tidak ada tagihan baru: semua sudah dibuat sebelumnya atau belum ada tarifnya.'
            : "{$summary['created']} tagihan dibuat, total ".Rupiah::format($summary['total']).'.';

        if ($summary['missing_count'] > 0) {
            $message .= " {$summary['missing_count']} siswa dilewati karena belum ada tarif untuk tingkatnya.";
        }

        return $this->done($message);
    }

    public function void(Request $request, string $invoice, ManageInvoices $invoices): RedirectResponse
    {
        $organizationId = $invoices->organizationOf($this->tenantId(), $invoice);

        if ($organizationId === null) {
            abort(404);
        }

        $this->authorizeAt($organizationId, FinanceAccess::INVOICES_MANAGE);

        $request->validate(['reason' => ['required', 'string', 'max:'.ManageInvoices::REASON_MAX_LENGTH]], [
            'reason.required' => 'Alasan pembatalan wajib diisi.',
        ], ['reason' => 'alasan']);

        $this->attempt(function () use ($invoices, $invoice, $request): bool {
            $invoices->void($this->tenantId(), $invoice, $request->string('reason')->value(), $this->tenantContext->require()->membershipId);

            return true;
        });

        return $this->done('Tagihan dibatalkan.');
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * @return array{organization_id: string, academic_year_id: string, fee_type_id: string, from: string, to: string}
     */
    private function generateInput(Request $request): array
    {
        $request->validate([
            'organization_id' => ['required', 'uuid'],
            'academic_year_id' => ['required', 'uuid'],
            'fee_type_id' => ['required', 'uuid'],
            'from' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'to' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ], [
            'fee_type_id.required' => 'Pilih jenis biaya bulanan.',
            'from.required' => 'Pilih bulan awal.',
            'to.required' => 'Pilih bulan akhir.',
        ]);

        $organizationId = $request->string('organization_id')->value();
        $this->authorizeAt($organizationId, FinanceAccess::INVOICES_MANAGE);

        return [
            'organization_id' => $organizationId,
            'academic_year_id' => $request->string('academic_year_id')->value(),
            'fee_type_id' => $request->string('fee_type_id')->value(),
            'from' => $request->string('from')->value(),
            'to' => $request->string('to')->value(),
        ];
    }

    private function query(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return mb_strlen($text) < 2 ? null : mb_substr($text, 0, 100);
    }
}
