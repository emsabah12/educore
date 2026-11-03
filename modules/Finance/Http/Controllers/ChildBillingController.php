<?php

namespace Modules\Finance\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Modules\Academic\Contracts\GuardianChildren;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Fee\Rupiah;
use Modules\Finance\Application\Online\OnlinePaymentList;
use Modules\Finance\Application\Online\OnlinePaymentSettings;
use Modules\Finance\Application\Payment\CashierOverview;
use Modules\Finance\Application\Payment\Receipts;
use Modules\Finance\Domain\Payment\PaymentStatus;

/**
 * Tagihan di portal orang tua/wali "Anak saya" (PRD-003 FN-5 §19): sisa tagihan, riwayat pembayaran sah, dan
 * kuitansi anaknya sendiri. Hanya baca.
 *
 * Akses TIDAK memakai izin/role: cukup tautan orang tua/wali (kontrak Academic GuardianChildren) antara
 * membership yang login dan siswa itu. Bukan anaknya, atau kuitansi milik siswa lain / yang dibatalkan → 404.
 */
final class ChildBillingController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly GuardianChildren $children,
    ) {}

    public function index(string $student, CashierOverview $overview, OnlinePaymentSettings $online, OnlinePaymentList $orders): Response
    {
        $context = $this->tenantContext->require();
        $child = $this->children->child($context->tenantId, $context->membershipId, $student) ?? abort(404);
        $today = BusinessClock::todayString();
        $organizations = [];
        $outstanding = 0;

        foreach ($child['enrollments'] as $enrollment) {
            $data = $overview->forStudent($context->tenantId, $enrollment['organization_id'], $enrollment['enrollment_id'], $today);

            if ($data === null) {
                continue;
            }

            $payments = [];

            foreach ($data['payments'] as $payment) {
                // Pembayaran yang dibatalkan petugas tidak ditampilkan ke orang tua [ASUMSI FN-5].
                if ($payment['status'] === PaymentStatus::Valid->value) {
                    $payments[] = [
                        'id' => $payment['id'],
                        'receipt_number' => $payment['receipt_number'],
                        'paid_on' => $payment['paid_on'],
                        'method' => $payment['method'],
                        'amount' => $payment['amount'],
                        'items' => $payment['items'],
                    ];
                }
            }

            // Lembaga lama (sudah keluar) hanya tampil bila masih ada tagihan atau riwayat bayar.
            if (! $enrollment['active'] && $data['invoices'] === [] && $payments === []) {
                continue;
            }

            $outstanding += $data['outstanding'];
            $organizations[] = [
                'enrollment_id' => $enrollment['enrollment_id'],
                'organization' => $enrollment['organization'],
                'student_number' => $enrollment['student_number'],
                'active' => $enrollment['active'],
                'class' => $data['student']['class'],
                'invoices' => $data['invoices'],
                'outstanding' => $data['outstanding'],
                'payments' => $payments,
            ];
        }

        return Inertia::render('finance/child-billing', [
            'student' => ['id' => $child['id'], 'name' => $child['name']],
            'organizations' => $organizations,
            'outstanding' => $outstanding,
            'today' => $today,
            'historyLimit' => CashierOverview::HISTORY_LIMIT,
            // PRD-006 MG-2: bayar online (aktif bila kunci terpasang & ada metode dibuka) + pesanan terbaru.
            'onlineActive' => $online->isActive($context->tenantId),
            'onlineOrders' => $orders->recent($context->tenantId, array_column($child['enrollments'], 'enrollment_id')),
        ]);
    }

    public function receipt(string $student, string $payment, Receipts $receipts): Response
    {
        $context = $this->tenantContext->require();
        $child = $this->children->child($context->tenantId, $context->membershipId, $student) ?? abort(404);
        $receipt = $receipts->find($context->tenantId, $payment);

        if ($receipt === null
            || $receipt['status'] !== PaymentStatus::Valid->value
            || ! in_array($receipt['enrollment_id'], array_column($child['enrollments'], 'enrollment_id'), true)) {
            abort(404);
        }

        return Inertia::render('print/receipt', [
            'receipt' => $receipt,
            'amountLabel' => Rupiah::format($receipt['amount']),
            'backUrl' => "/anak-saya/{$child['id']}/tagihan",
            'backLabel' => 'Kembali ke tagihan',
        ]);
    }
}
