<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Academic\Contracts\GuardianChildren;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Online\ManageOnlinePayments;
use Modules\Finance\Application\Online\OnlinePaymentList;
use Modules\Finance\Application\Online\OnlinePaymentSettings;
use Modules\Finance\Application\Payment\CashierOverview;
use Modules\Finance\Domain\Fee\Exceptions\FinanceException;
use Modules\Finance\Domain\Online\OnlinePaymentStatus;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Bayar online di portal orang tua/wali "Anak saya → Tagihan" (PRD-006 §4, MG-2): pilih tagihan & metode, diarahkan ke
 * halaman pembayaran Midtrans (Snap), halaman status, cek status, lanjutkan, batalkan.
 *
 * Akses TIDAK memakai izin/role: cukup tautan orang tua/wali ke siswa itu (kontrak Academic GuardianChildren), sama
 * dengan Tagihan (PRD-003 FN-5). Bukan anaknya / pesanan anak lain → 404. Pembayaran online belum aktif → 404.
 */
final class ChildOnlinePaymentController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly GuardianChildren $children,
    ) {}

    public function create(string $student, CashierOverview $overview, OnlinePaymentSettings $settings): Response
    {
        $context = $this->tenantContext->require();
        $child = $this->child($student);
        $methods = $settings->enabledMethods($context->tenantId) ?: abort(404);
        $today = BusinessClock::todayString();
        $organizations = [];

        foreach ($child['enrollments'] as $enrollment) {
            $data = $overview->forStudent($context->tenantId, $enrollment['organization_id'], $enrollment['enrollment_id'], $today);

            if ($data === null || $data['invoices'] === []) {
                continue;
            }

            $organizations[] = [
                'enrollment_id' => $enrollment['enrollment_id'],
                'organization' => $enrollment['organization'],
                'invoices' => $data['invoices'],
            ];
        }

        return Inertia::render('finance/child-online-pay', [
            'student' => ['id' => $child['id'], 'name' => $child['name']],
            'organizations' => $organizations,
            'methods' => array_map(fn (array $row): array => [
                'value' => $row['method'],
                'label' => $row['label'],
                'fee_is_percent' => $row['fee_is_percent'],
                'fee_value' => $row['fee_value'],
                'fee_label' => $row['fee_label'],
            ], $methods),
        ]);
    }

    public function store(Request $request, string $student, ManageOnlinePayments $online): HttpResponse
    {
        $context = $this->tenantContext->require();
        $child = $this->child($student);

        $request->validate([
            'enrollment_id' => ['required', 'uuid'],
            'invoice_ids' => ['required', 'array', 'min:1', 'max:200'],
            'invoice_ids.*' => ['uuid'],
            'method' => ['required', 'string', 'max:20'],
        ], [
            'invoice_ids.required' => 'Pilih minimal satu tagihan.',
            'invoice_ids.min' => 'Pilih minimal satu tagihan.',
            'method.required' => 'Pilih metode pembayaran.',
        ]);

        $enrollment = $this->enrollment($child, $request->string('enrollment_id')->value());

        try {
            $order = $online->create(
                $context->tenantId,
                $enrollment['organization_id'],
                $enrollment['enrollment_id'],
                array_values(array_map('strval', (array) $request->input('invoice_ids'))),
                $request->string('method')->value(),
                // Midtrans mengarahkan orang tua kembali ke halaman status (callbacks.finish).
                url("/anak-saya/{$child['id']}/bayar-online/selesai"),
                $context->membershipId,
            );
        } catch (FinanceException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        // Halaman pembayaran Midtrans ada di domain lain: kunjungan penuh, bukan Inertia.
        return Inertia::location($order['redirect_url']);
    }

    /**
     * Kembali dari Midtrans (`callbacks.finish` + `?order_id=`): cek status sekali lalu tampilkan halaman status.
     */
    public function finish(Request $request, string $student, ManageOnlinePayments $online): RedirectResponse
    {
        $context = $this->tenantContext->require();
        $child = $this->child($student);
        $orderId = $request->string('order_id')->value();
        $order = $orderId === '' ? null : DB::table('fn_online_payments')
            ->where('tenant_id', $context->tenantId)
            ->where('order_id', $orderId)
            ->whereIn('enrollment_id', array_column($child['enrollments'], 'enrollment_id'))
            ->first(['id']);

        if ($order === null) {
            return redirect("/anak-saya/{$child['id']}/tagihan");
        }

        try {
            $online->refresh($context->tenantId, (string) $order->id);
        } catch (FinanceException) {
            // Midtrans belum bisa dihubungi: status tetap seperti tercatat; orang tua bisa menekan "Cek status".
        }

        return redirect("/anak-saya/{$child['id']}/bayar-online/{$order->id}");
    }

    public function show(string $student, string $order, OnlinePaymentList $list): Response
    {
        $child = $this->child($student);
        $row = $this->order($child, $order, $list);

        return Inertia::render('finance/child-online-status', [
            'student' => ['id' => $child['id'], 'name' => $child['name']],
            'order' => $row,
            'organization' => collect($child['enrollments'])->firstWhere('enrollment_id', $row['enrollment_id'])['organization'] ?? '',
        ]);
    }

    public function check(string $student, string $order, OnlinePaymentList $list, ManageOnlinePayments $online): RedirectResponse
    {
        $child = $this->child($student);
        $row = $this->order($child, $order, $list);
        $this->attempt(fn () => $online->refresh($this->tenantContext->require()->tenantId, $row['id']));

        return back();
    }

    public function resume(string $student, string $order, OnlinePaymentList $list): HttpResponse
    {
        $child = $this->child($student);
        $row = $this->order($child, $order, $list);
        $url = DB::table('fn_online_payments')->where('id', $row['id'])->value('redirect_url');

        if ($row['status'] !== OnlinePaymentStatus::Pending->value || ! is_string($url)) {
            return redirect("/anak-saya/{$child['id']}/bayar-online/{$row['id']}");
        }

        return Inertia::location($url);
    }

    public function cancel(string $student, string $order, OnlinePaymentList $list, ManageOnlinePayments $online): RedirectResponse
    {
        $context = $this->tenantContext->require();
        $child = $this->child($student);
        $row = $this->order($child, $order, $list);
        $this->attempt(fn () => $online->cancel($context->tenantId, $row['id'], $context->membershipId));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pembayaran online dibatalkan. Tagihan tetap bisa dibayar kapan saja.']);

        return back();
    }

    /**
     * @return array{id: string, name: string, enrollments: list<array{enrollment_id: string, organization_id: string, organization: string, student_number: string|null, active: bool}>}
     */
    private function child(string $student): array
    {
        $context = $this->tenantContext->require();

        return $this->children->child($context->tenantId, $context->membershipId, $student) ?? abort(404);
    }

    /**
     * @param  array{enrollments: list<array{enrollment_id: string, organization_id: string, organization: string, student_number: string|null, active: bool}>}  $child
     * @return array{enrollment_id: string, organization_id: string, organization: string, student_number: string|null, active: bool}
     */
    private function enrollment(array $child, string $enrollmentId): array
    {
        foreach ($child['enrollments'] as $enrollment) {
            if ($enrollment['enrollment_id'] === $enrollmentId) {
                return $enrollment;
            }
        }

        abort(404);
    }

    /**
     * @param  array{enrollments: list<array{enrollment_id: string}>}  $child
     * @return array<string, mixed>&array{id: string, enrollment_id: string, status: string}
     */
    private function order(array $child, string $order, OnlinePaymentList $list): array
    {
        $row = $list->find($this->tenantContext->require()->tenantId, $order);

        if ($row === null || ! in_array($row['enrollment_id'], array_column($child['enrollments'], 'enrollment_id'), true)) {
            abort(404);
        }

        return $row;
    }

    private function attempt(callable $action): void
    {
        try {
            $action();
        } catch (FinanceException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }
    }
}
