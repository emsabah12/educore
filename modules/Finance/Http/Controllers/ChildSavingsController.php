<?php

namespace Modules\Finance\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Modules\Academic\Contracts\GuardianChildren;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Finance\Application\Fee\Rupiah;
use Modules\Finance\Application\Savings\SavingsOverview;
use Modules\Finance\Application\Savings\SavingsSlips;
use Modules\Finance\Domain\Payment\PaymentStatus;

/**
 * Tabungan di portal orang tua/wali "Anak saya" (PRD-005 §6, TB-4): saldo per lembaga, 30 transaksi SAH terakhir,
 * dan slip anaknya sendiri. Hanya baca; setor/tarik tetap lewat kasir sekolah.
 *
 * Akses TIDAK memakai izin/role: cukup tautan orang tua/wali (kontrak Academic GuardianChildren) antara membership
 * yang login dan siswa itu (sama dengan Tagihan, PRD-003 FN-5). Bukan anaknya, atau slip milik siswa lain / yang
 * dibatalkan → 404.
 */
final class ChildSavingsController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly GuardianChildren $children,
    ) {}

    public function index(string $student, SavingsOverview $overview): Response
    {
        $context = $this->tenantContext->require();
        $child = $this->children->child($context->tenantId, $context->membershipId, $student) ?? abort(404);
        $organizations = [];
        $seen = [];
        $total = 0;

        // Pendaftaran urut: yang berjalan dulu; satu rekening tabungan per lembaga.
        foreach ($child['enrollments'] as $enrollment) {
            if (isset($seen[$enrollment['organization_id']])) {
                continue;
            }

            $seen[$enrollment['organization_id']] = true;
            $data = $overview->forStudent($context->tenantId, $enrollment['organization_id'], $child['id'], validOnly: true);

            if (! $data['exists']) {
                continue;
            }

            $entries = [];

            foreach ($data['entries'] as $entry) {
                $entries[] = [
                    'id' => $entry['id'],
                    'slip_number' => $entry['slip_number'],
                    'entry_date' => $entry['entry_date'],
                    'kind' => $entry['kind'],
                    'kind_label' => $entry['kind_label'],
                    'method' => $entry['method'],
                    'amount' => $entry['amount'],
                    'balance_after' => $entry['balance_after'],
                    'taken_by' => $entry['taken_by'],
                    'note' => $entry['note'],
                ];
            }

            $total += $data['balance'];
            $organizations[] = [
                'organization_id' => $enrollment['organization_id'],
                'organization' => $enrollment['organization'],
                'active' => $enrollment['active'],
                'balance' => $data['balance'],
                'entries' => $entries,
            ];
        }

        return Inertia::render('finance/child-savings', [
            'student' => ['id' => $child['id'], 'name' => $child['name']],
            'organizations' => $organizations,
            'total' => $total,
            'historyLimit' => SavingsOverview::HISTORY_LIMIT,
        ]);
    }

    public function slip(string $student, string $entry, SavingsSlips $slips): Response
    {
        $context = $this->tenantContext->require();
        $child = $this->children->child($context->tenantId, $context->membershipId, $student) ?? abort(404);
        $slip = $slips->find($context->tenantId, $entry);

        if ($slip === null
            || $slip['status'] !== PaymentStatus::Valid->value
            || ! in_array($slip['enrollment_id'], array_column($child['enrollments'], 'enrollment_id'), true)) {
            abort(404);
        }

        return Inertia::render('print/savings-slip', [
            'slip' => $slip,
            'amountLabel' => Rupiah::format($slip['amount']),
            'balanceLabel' => Rupiah::format($slip['balance_after']),
            'backUrl' => "/anak-saya/{$child['id']}/tabungan",
            'backLabel' => 'Kembali ke tabungan',
        ]);
    }
}
