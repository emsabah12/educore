<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Academic\Contracts\EnrolledStudents;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Authorization\FinanceAccess;
use Modules\Finance\Application\Fee\Rupiah;
use Modules\Finance\Application\Payment\CashierOverview;
use Modules\Finance\Application\Payment\ManagePayments;
use Modules\Finance\Application\Payment\Receipts;
use Modules\Finance\Domain\Payment\PaymentMethod;
use Modules\Finance\Http\Controllers\Concerns\WorksPerOrganization;

/**
 * Kasir & kuitansi (PRD-003 §4, §6, FN-3): cari siswa → tagihan belum lunas → catat pembayaran →
 * kuitansi; riwayat & batal pembayaran.
 *
 * - Lihat (layar kasir hanya-baca, kuitansi): `finance.billing.view` di lembaga itu.
 * - Catat & batalkan pembayaran: `finance.payments.manage` di lembaga itu (Bendahara, Admin Keuangan,
 *   Admin Yayasan). Lembaga di luar cakupan → 404; tanpa izin → 403.
 */
final class CashierController
{
    use WorksPerOrganization;

    public const SEARCH_LIMIT = 10;

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, CashierOverview $overview): Response
    {
        $organizations = $this->organizations();

        if ($organizations === []) {
            abort(403);
        }

        $organizationId = $this->pick(array_column($organizations, 'value'), $request->query('lembaga'));
        $enrollmentId = $request->query('siswa');
        $student = $organizationId !== null && is_string($enrollmentId) && Str::isUuid($enrollmentId)
            ? $overview->forStudent($this->tenantId(), $organizationId, $enrollmentId, BusinessClock::todayString())
            : null;

        return Inertia::render('finance/cashier', [
            'organizations' => $organizations,
            'organizationId' => $organizationId,
            'student' => $student,
            'canPay' => $organizationId !== null && $this->authorization->allows(FinanceAccess::PAYMENTS_MANAGE, $organizationId),
            'today' => BusinessClock::todayString(),
            'methods' => array_map(fn (PaymentMethod $method): array => ['value' => $method->value, 'label' => $method->label()], PaymentMethod::cases()),
            'noteMax' => ManagePayments::NOTE_MAX_LENGTH,
            'voidReasonMax' => ManagePayments::REASON_MAX_LENGTH,
        ]);
    }

    /**
     * Cari siswa di lembaga untuk kasir (JSON), termasuk yang sudah keluar (bisa masih punya tunggakan).
     * Dibatasi throttle di route.
     */
    public function searchStudents(Request $request, EnrolledStudents $students): JsonResponse
    {
        $organizationId = $request->string('lembaga')->value();
        $this->authorizeAt($organizationId, FinanceAccess::BILLING_VIEW);

        $query = trim($request->string('q')->value());

        if (mb_strlen($query) < 2) {
            return response()->json(['message' => 'Ketik minimal 2 huruf nama, atau awal NISN / nomor induk.', 'results' => []], 422);
        }

        return response()->json(['results' => $students->search($this->tenantId(), $organizationId, mb_substr($query, 0, 100), self::SEARCH_LIMIT, includeClosed: true)]);
    }

    public function store(Request $request, ManagePayments $payments): RedirectResponse
    {
        $request->validate([
            'organization_id' => ['required', 'uuid'],
            'enrollment_id' => ['required', 'uuid'],
            'amount' => ['required', 'string', 'max:30'],
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'note' => ['nullable', 'string', 'max:'.ManagePayments::NOTE_MAX_LENGTH],
            'invoice_ids' => ['nullable', 'array', 'max:200'],
            'invoice_ids.*' => ['uuid'],
        ], [
            'enrollment_id.required' => 'Pilih siswa.',
            'amount.required' => 'Isi jumlah bayar.',
            'paid_on.required' => 'Isi tanggal bayar.',
            'paid_on.date_format' => 'Isi tanggal bayar yang benar.',
            'method.required' => 'Pilih cara bayar.',
        ], ['note' => 'catatan']);

        $organizationId = $request->string('organization_id')->value();
        $this->authorizeAt($organizationId, FinanceAccess::PAYMENTS_MANAGE);

        /** @var list<string>|null $invoiceIds */
        $invoiceIds = $request->filled('invoice_ids') ? array_values(array_map('strval', (array) $request->input('invoice_ids'))) : null;

        $paymentId = $this->attempt(fn (): string => $payments->record(
            $this->tenantId(),
            $organizationId,
            $request->string('enrollment_id')->value(),
            $request->input('amount'),
            $request->string('paid_on')->value(),
            $request->string('method')->value(),
            $request->filled('note') ? $request->string('note')->value() : null,
            $invoiceIds,
            $this->tenantContext->require()->membershipId,
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pembayaran dicatat. Cetak kuitansinya di halaman ini.']);

        return redirect()->route('finance.receipts.show', ['payment' => $paymentId]);
    }

    public function void(Request $request, string $payment, ManagePayments $payments): RedirectResponse
    {
        $organizationId = $payments->organizationOf($this->tenantId(), $payment);

        if ($organizationId === null) {
            abort(404);
        }

        $this->authorizeAt($organizationId, FinanceAccess::PAYMENTS_MANAGE);

        $request->validate(['reason' => ['required', 'string', 'max:'.ManagePayments::REASON_MAX_LENGTH]], [
            'reason.required' => 'Alasan pembatalan wajib diisi.',
        ], ['reason' => 'alasan']);

        $this->attempt(function () use ($payments, $payment, $request): bool {
            $payments->void($this->tenantId(), $payment, $request->string('reason')->value(), $this->tenantContext->require()->membershipId);

            return true;
        });

        return $this->done('Pembayaran dibatalkan; tagihannya terbuka kembali.');
    }

    /**
     * Halaman cetak kuitansi. Di luar cakupan lihat tagihan → 404.
     */
    public function receipt(string $payment, Receipts $receipts): Response
    {
        $receipt = $receipts->find($this->tenantId(), $payment);

        if ($receipt === null || ! $this->authorization->allows(FinanceAccess::BILLING_VIEW, $receipt['organization_id'])) {
            abort(404);
        }

        return Inertia::render('print/receipt', [
            'receipt' => $receipt,
            'amountLabel' => Rupiah::format($receipt['amount']),
            'backUrl' => '/keuangan/kasir?'.http_build_query(['lembaga' => $receipt['organization_id'], 'siswa' => $receipt['enrollment_id']]),
        ]);
    }
}
