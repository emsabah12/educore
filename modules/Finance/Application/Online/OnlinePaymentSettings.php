<?php

namespace Modules\Finance\Application\Online;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Application\Fee\Rupiah;
use Modules\Finance\Domain\Fee\Exceptions\FinanceException;
use Modules\Finance\Domain\Online\OnlinePaymentMethod;

/**
 * Pengaturan pembayaran online per yayasan (PRD-006 §6, MG-1): metode yang dibuka dan biaya layanan per metode.
 * Pembayaran online "aktif" bila kunci Midtrans terpasang dan minimal satu metode dibuka. Hak akses
 * (finance.settings.manage tingkat Yayasan) dicek pemanggil.
 *
 * Aturan [ASUMSI MG-1]: biaya VA nominal Rp 0–50.000; biaya QRIS 0–10% dengan paling banyak 2 angka desimal
 * (disimpan basis poin). Membuka metode wajib kunci Midtrans terpasang. Aplikasi tidak memasang tarif bawaan [CEK].
 *
 * @phpstan-type MethodRow array{method: string, label: string, enabled: bool, fee_value: int, fee_is_percent: bool, fee_input: string, fee_label: string}
 */
final class OnlinePaymentSettings
{
    public const MAX_FIXED_FEE = 50_000;

    /** 10% dalam basis poin. */
    public const MAX_PERCENT_FEE = 1_000;

    public function __construct(
        private readonly MidtransGateway $gateway,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Semua metode (urut enum) beserta pengaturannya.
     *
     * @return list<MethodRow>
     */
    public function methods(string $tenantId): array
    {
        $stored = [];

        foreach (DB::table('fn_online_payment_methods')->where('tenant_id', $tenantId)->get(['method', 'is_enabled', 'fee_value']) as $row) {
            $stored[(string) $row->method] = ['enabled' => (bool) $row->is_enabled, 'fee' => (int) $row->fee_value];
        }

        $rows = [];

        foreach (OnlinePaymentMethod::cases() as $method) {
            $enabled = $stored[$method->value]['enabled'] ?? false;
            $fee = $stored[$method->value]['fee'] ?? 0;
            $rows[] = [
                'method' => $method->value,
                'label' => $method->label(),
                'enabled' => $enabled,
                'fee_value' => $fee,
                'fee_is_percent' => $method->feeIsPercent(),
                'fee_input' => $method->feeIsPercent() ? self::percentInput($fee) : (string) $fee,
                'fee_label' => self::feeLabel($method, $fee),
            ];
        }

        return $rows;
    }

    /**
     * Metode yang dibuka (kosong bila kunci Midtrans belum terpasang).
     *
     * @return list<MethodRow>
     */
    public function enabledMethods(string $tenantId): array
    {
        if (! $this->gateway->isConfigured()) {
            return [];
        }

        return array_values(array_filter($this->methods($tenantId), fn (array $row): bool => $row['enabled']));
    }

    public function isActive(string $tenantId): bool
    {
        return $this->enabledMethods($tenantId) !== [];
    }

    /**
     * Simpan semua metode sekaligus.
     *
     * @param  array<string, array{enabled: bool, fee: string}>  $input  kunci = nilai OnlinePaymentMethod
     *
     * @throws FinanceException
     */
    public function save(string $tenantId, array $input): void
    {
        $values = [];

        foreach (OnlinePaymentMethod::cases() as $method) {
            $row = $input[$method->value] ?? ['enabled' => false, 'fee' => '0'];
            $field = 'methods.'.$method->value.'.fee';
            $values[$method->value] = [
                'enabled' => $row['enabled'],
                'fee' => $method->feeIsPercent() ? self::parsePercent($row['fee'], $field) : Rupiah::parse($row['fee'] === '' ? '0' : $row['fee'], $field, 'biaya '.$method->label(), 0, self::MAX_FIXED_FEE),
            ];
        }

        $enabling = array_filter($values, fn (array $row): bool => $row['enabled']) !== [];

        if ($enabling && ! $this->gateway->isConfigured()) {
            throw FinanceException::make('form', 'Kunci Midtrans belum dipasang di server (.env). Hubungi pengelola server sebelum membuka metode pembayaran.');
        }

        DB::transaction(function () use ($tenantId, $values): void {
            $before = [];

            foreach ($this->methods($tenantId) as $row) {
                $before[$row['method']] = ['enabled' => $row['enabled'], 'fee' => $row['fee_value']];
            }

            $changes = [];
            $now = now();

            foreach ($values as $method => $row) {
                if ($before[$method] === $row) {
                    continue;
                }

                $changes[$method] = ['aktif' => [$before[$method]['enabled'], $row['enabled']], 'biaya' => [$before[$method]['fee'], $row['fee']]];

                DB::table('fn_online_payment_methods')->upsert([[
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'method' => $method,
                    'is_enabled' => $row['enabled'],
                    'fee_value' => $row['fee'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]], ['tenant_id', 'method'], ['is_enabled', 'fee_value', 'updated_at']);
            }

            if ($changes !== []) {
                $this->audit->record(
                    FinanceAuditAction::ONLINE_SETTINGS_UPDATED,
                    FinanceAuditAction::SUBJECT_ONLINE_SETTINGS,
                    $tenantId,
                    $changes,
                    tenantId: $tenantId,
                );
            }
        });
    }

    public static function feeLabel(OnlinePaymentMethod $method, int $fee): string
    {
        if ($method->feeIsPercent()) {
            return $fee === 0 ? 'Tanpa biaya' : self::percentInput($fee).'% dari tagihan';
        }

        return $fee === 0 ? 'Tanpa biaya' : Rupiah::format($fee).' per transaksi';
    }

    /**
     * "0,7" / "0.70" / "1" → basis poin (70 / 70 / 100).
     *
     * @throws FinanceException
     */
    private static function parsePercent(string $value, string $field): int
    {
        $value = str_replace(',', '.', trim($value));
        $value = $value === '' ? '0' : $value;

        if (preg_match('/^\d{1,2}(\.\d{1,2})?$/', $value) !== 1) {
            throw FinanceException::make($field, 'Isi persen biaya QRIS dengan angka, mis. 0,7 (paling banyak 2 angka desimal).');
        }

        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');
        $basisPoints = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');

        if ($basisPoints > self::MAX_PERCENT_FEE) {
            throw FinanceException::make($field, 'Biaya QRIS paling besar 10%.');
        }

        return $basisPoints;
    }

    private static function percentInput(int $basisPoints): string
    {
        $text = intdiv($basisPoints, 100).','.str_pad((string) ($basisPoints % 100), 2, '0', STR_PAD_LEFT);

        return rtrim(rtrim($text, '0'), ',');
    }
}
