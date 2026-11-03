<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Academic\Contracts\EnrolledStudents;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Authorization\FinanceAccess;
use Modules\Finance\Application\Online\ManageOnlinePayments;
use Modules\Finance\Application\Online\OnlinePaymentList;
use Modules\Finance\Application\Payment\ManagePayments;
use Modules\Finance\Application\Report\ReceiptRecap;
use Modules\Finance\Domain\Online\OnlinePaymentStatus;
use Modules\Finance\Http\Controllers\Concerns\WorksPerOrganization;

/**
 * Pembayaran online untuk petugas (PRD-006 §8–§9, MG-3): daftar pesanan per lembaga dalam cakupan, rincian + jejak
 * notifikasi Midtrans, cek status, dan tandai kelebihan bayar sudah dikembalikan.
 *
 * - Lihat: `finance.billing.view` di lembaga itu (lembaga di luar cakupan → 404).
 * - Cek status & tandai kelebihan bayar: `finance.payments.manage` di lembaga itu (→ 403).
 *
 * Saringan (query): `lembaga` (kosong = semua dalam cakupan), `status` (status pesanan atau KELEBIHAN), `bulan` (Y-m
 * tanggal dibuat), `halaman`.
 */
final class OnlinePaymentController
{
    use WorksPerOrganization;

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, OnlinePaymentList $list): Response
    {
        $organizations = $this->organizations();

        if ($organizations === []) {
            abort(403);
        }

        $allIds = array_column($organizations, 'value');
        $organizationId = $this->pick($allIds, $request->query('lembaga'), '') ?: null;
        $ids = $organizationId === null ? $allIds : [$organizationId];
        $statuses = [...array_map(fn (OnlinePaymentStatus $status): string => $status->value, OnlinePaymentStatus::cases()), OnlinePaymentList::FILTER_OVERPAID];
        $status = $this->pick($statuses, $request->query('status'), '') ?: null;
        $months = ReceiptRecap::monthOptions(BusinessClock::today());
        $month = $this->pick(array_column($months, 'value'), $request->query('bulan'), '') ?: null;
        $data = $list->forStaff($this->tenantId(), $ids, $status, $month, $request->integer('halaman', 1));
        $names = array_column($organizations, 'label', 'value');

        foreach ($data['rows'] as $index => $row) {
            $data['rows'][$index]['organization'] = $names[$row['organization_id']] ?? '';
        }

        return Inertia::render('finance/online-payments', [
            'organizations' => $organizations,
            'organizationId' => $organizationId,
            'statusOptions' => [
                ...array_map(fn (OnlinePaymentStatus $item): array => ['value' => $item->value, 'label' => $item->label()], OnlinePaymentStatus::cases()),
                ['value' => OnlinePaymentList::FILTER_OVERPAID, 'label' => 'Kelebihan bayar (belum dikembalikan)'],
            ],
            'status' => $status,
            'months' => $months,
            'month' => $month,
            'orders' => $data,
            'overpaid' => $list->overpaid($this->tenantId(), $ids),
        ]);
    }

    public function show(string $order, OnlinePaymentList $list, EnrolledStudents $students): Response
    {
        $row = $list->find($this->tenantId(), $order) ?? abort(404);
        $this->authorizeAt($row['organization_id'], FinanceAccess::BILLING_VIEW);
        $student = $students->describe($this->tenantId(), [$row['enrollment_id']])[$row['enrollment_id']] ?? null;

        return Inertia::render('finance/online-payment-show', [
            'order' => $row,
            'student' => [
                'name' => $student['name'] ?? '(siswa tidak ditemukan)',
                'class' => $student['class'] ?? null,
                'student_number' => $student['student_number'] ?? null,
            ],
            'organization' => (string) DB::table('organizations')->where('id', $row['organization_id'])->value('name'),
            'receiptNumber' => $row['payment_id'] === null ? null : (string) DB::table('fn_payments')->where('id', $row['payment_id'])->value('receipt_number'),
            'events' => $list->events($row['id']),
            'canManage' => $this->authorization->allows(FinanceAccess::PAYMENTS_MANAGE, $row['organization_id']),
            'cashierUrl' => '/keuangan/kasir?'.http_build_query(['lembaga' => $row['organization_id'], 'siswa' => $row['enrollment_id']]),
            'noteMax' => ManagePayments::NOTE_MAX_LENGTH,
        ]);
    }

    public function check(string $order, OnlinePaymentList $list, ManageOnlinePayments $online): RedirectResponse
    {
        $row = $list->find($this->tenantId(), $order) ?? abort(404);
        $this->authorizeAt($row['organization_id'], FinanceAccess::PAYMENTS_MANAGE);

        $this->attempt(function () use ($online, $row): bool {
            $online->refresh($this->tenantId(), $row['id']);

            return true;
        });

        return $this->done('Status diperbarui dari Midtrans.');
    }

    public function settleOverpaid(Request $request, string $order, OnlinePaymentList $list, ManageOnlinePayments $online): RedirectResponse
    {
        $row = $list->find($this->tenantId(), $order) ?? abort(404);
        $this->authorizeAt($row['organization_id'], FinanceAccess::PAYMENTS_MANAGE);

        $request->validate(['note' => ['nullable', 'string', 'max:'.ManagePayments::NOTE_MAX_LENGTH]], [], ['note' => 'catatan']);

        $this->attempt(function () use ($online, $row, $request): bool {
            $online->settleOverpaid($this->tenantId(), $row['id'], $request->filled('note') ? $request->string('note')->value() : null);

            return true;
        });

        return $this->done('Kelebihan bayar ditandai sudah dikembalikan.');
    }
}
