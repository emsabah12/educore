<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Finance\Application\Authorization\FinanceAccess;
use Modules\Finance\Application\Fee\Rupiah;
use Modules\Finance\Application\Savings\ManageSavings;
use Modules\Finance\Application\Savings\SavingsSlips;
use Modules\Finance\Domain\Payment\PaymentMethod;
use Modules\Finance\Http\Controllers\Concerns\WorksPerOrganization;

/**
 * Tabungan siswa di Kasir (PRD-005 §4–§5, TB-1): setor, tarik, batalkan, cetak slip.
 *
 * - Catat & batalkan: `finance.savings.manage` di lembaga siswa (Bendahara, Admin Keuangan, Admin Yayasan).
 * - Slip: `finance.savings.view` di lembaga itu. Lembaga di luar cakupan → 404; tanpa izin → 403.
 */
final class SavingsController
{
    use WorksPerOrganization;

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function deposit(Request $request, ManageSavings $savings): RedirectResponse
    {
        $this->validateEntry($request, false);
        $organizationId = $request->string('organization_id')->value();
        $this->authorizeAt($organizationId, FinanceAccess::SAVINGS_MANAGE);

        $entryId = $this->attempt(fn (): string => $savings->deposit(
            $this->tenantId(),
            $organizationId,
            $request->string('enrollment_id')->value(),
            $request->input('amount'),
            $request->string('entry_date')->value(),
            $request->string('method')->value(),
            $request->filled('note') ? $request->string('note')->value() : null,
            $this->tenantContext->require()->membershipId,
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Setoran tabungan dicatat. Cetak slipnya di halaman ini.']);

        return redirect()->route('finance.savings.slip', ['entry' => $entryId]);
    }

    public function withdraw(Request $request, ManageSavings $savings): RedirectResponse
    {
        $this->validateEntry($request, true);
        $organizationId = $request->string('organization_id')->value();
        $this->authorizeAt($organizationId, FinanceAccess::SAVINGS_MANAGE);

        $entryId = $this->attempt(fn (): string => $savings->withdraw(
            $this->tenantId(),
            $organizationId,
            $request->string('enrollment_id')->value(),
            $request->input('amount'),
            $request->string('entry_date')->value(),
            $request->string('method')->value(),
            $request->string('taken_by')->value(),
            $request->filled('note') ? $request->string('note')->value() : null,
            $this->tenantContext->require()->membershipId,
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Penarikan tabungan dicatat. Cetak slipnya di halaman ini.']);

        return redirect()->route('finance.savings.slip', ['entry' => $entryId]);
    }

    public function void(Request $request, string $entry, ManageSavings $savings): RedirectResponse
    {
        $organizationId = $savings->organizationOf($this->tenantId(), $entry);

        if ($organizationId === null) {
            abort(404);
        }

        $this->authorizeAt($organizationId, FinanceAccess::SAVINGS_MANAGE);

        $request->validate(['reason' => ['required', 'string', 'max:'.ManageSavings::REASON_MAX_LENGTH]], [
            'reason.required' => 'Alasan pembatalan wajib diisi.',
        ], ['reason' => 'alasan']);

        $this->attempt(function () use ($savings, $entry, $request): bool {
            $savings->void($this->tenantId(), $entry, $request->string('reason')->value(), $this->tenantContext->require()->membershipId);

            return true;
        });

        return $this->done('Transaksi tabungan dibatalkan; saldo dihitung ulang.');
    }

    /**
     * Halaman cetak slip. Di luar cakupan lihat tabungan → 404.
     */
    public function slip(string $entry, SavingsSlips $slips): Response
    {
        $slip = $slips->find($this->tenantId(), $entry);

        if ($slip === null || ! $this->authorization->allows(FinanceAccess::SAVINGS_VIEW, $slip['organization_id'])) {
            abort(404);
        }

        return Inertia::render('print/savings-slip', [
            'slip' => $slip,
            'amountLabel' => Rupiah::format($slip['amount']),
            'balanceLabel' => Rupiah::format($slip['balance_after']),
            'backUrl' => '/keuangan/kasir?'.http_build_query(['lembaga' => $slip['organization_id'], 'siswa' => $slip['enrollment_id'], 'tab' => 'tabungan']),
        ]);
    }

    private function validateEntry(Request $request, bool $withdrawal): void
    {
        $request->validate([
            'organization_id' => ['required', 'uuid'],
            'enrollment_id' => ['required', 'uuid'],
            'amount' => ['required', 'string', 'max:30'],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            // Setor/tarik hanya tunai atau transfer (TABUNGAN khusus kuitansi).
            'method' => ['required', Rule::in([PaymentMethod::Cash->value, PaymentMethod::Transfer->value])],
            'taken_by' => $withdrawal ? ['required', 'string', 'max:'.ManageSavings::TAKEN_BY_MAX_LENGTH] : ['prohibited'],
            'note' => ['nullable', 'string', 'max:'.ManageSavings::NOTE_MAX_LENGTH],
        ], [
            'enrollment_id.required' => 'Pilih siswa.',
            'amount.required' => $withdrawal ? 'Isi jumlah penarikan.' : 'Isi jumlah setoran.',
            'entry_date.required' => 'Isi tanggal.',
            'entry_date.date_format' => 'Isi tanggal yang benar.',
            'method.required' => 'Pilih cara: tunai atau transfer.',
            'taken_by.required' => 'Isi nama yang mengambil uang.',
        ], ['note' => 'catatan', 'taken_by' => 'diambil oleh']);
    }
}
