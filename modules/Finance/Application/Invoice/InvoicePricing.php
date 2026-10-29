<?php

namespace Modules\Finance\Application\Invoice;

use Illuminate\Support\Facades\DB;
use Modules\Finance\Application\Fee\Rupiah;

/**
 * Tarif & potongan untuk membuat tagihan (PRD-003 §4, FN-2a/FN-2b), dipakai bersama tagihan bulanan dan
 * sekali bayar.
 *
 * - Tarif: tingkat kelas siswa didahulukan, lalu "semua tingkat".
 * - Potongan: persen dibulatkan ke bawah ke rupiah; nominal maks. sebesar tarif.
 */
final class InvoicePricing
{
    /** @var array<int|string, int> tingkat → nominal; 'all' = semua tingkat */
    private array $rates = [];

    /** @var array<string, array{percent: int|null, amount: int|null}> pendaftaran → potongan */
    private array $discounts = [];

    public static function load(string $typeId, string $organizationId, string $yearId): self
    {
        $pricing = new self;

        foreach (DB::table('fn_fee_rates')->where('fee_type_id', $typeId)->where('organization_id', $organizationId)->where('academic_year_id', $yearId)->get(['grade', 'amount']) as $rate) {
            $pricing->rates[$rate->grade === null ? 'all' : (int) $rate->grade] = (int) $rate->amount;
        }

        foreach (DB::table('fn_discounts')->where('fee_type_id', $typeId)->where('organization_id', $organizationId)->where('academic_year_id', $yearId)->get(['enrollment_id', 'percent', 'amount']) as $discount) {
            $pricing->discounts[(string) $discount->enrollment_id] = [
                'percent' => $discount->percent === null ? null : (int) $discount->percent,
                'amount' => $discount->amount === null ? null : (int) $discount->amount,
            ];
        }

        return $pricing;
    }

    /**
     * Harga satu tagihan, atau null bila tidak ada tarif untuk tingkat ini.
     *
     * @return array{base_amount: int, discount_amount: int, discount_label: string|null, amount: int}|null
     */
    public function price(string $enrollmentId, ?int $grade): ?array
    {
        $base = ($grade !== null ? ($this->rates[$grade] ?? null) : null) ?? ($this->rates['all'] ?? null);

        if ($base === null) {
            return null;
        }

        $discount = $this->discounts[$enrollmentId] ?? null;
        $amount = 0;
        $label = null;

        if ($discount !== null && $discount['percent'] !== null) {
            $amount = intdiv($base * $discount['percent'], 100);
            $label = "{$discount['percent']}%";
        } elseif ($discount !== null && $discount['amount'] !== null) {
            $amount = min($discount['amount'], $base);
            $label = Rupiah::format($discount['amount']);
        }

        return ['base_amount' => $base, 'discount_amount' => $amount, 'discount_label' => $label, 'amount' => $base - $amount];
    }
}
