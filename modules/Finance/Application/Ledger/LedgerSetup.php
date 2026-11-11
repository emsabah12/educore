<?php

namespace Modules\Finance\Application\Ledger;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Domain\Cash\CashAccountKind;
use Modules\Finance\Domain\Cash\CashDirection;
use Modules\Finance\Domain\Fee\Exceptions\FinanceException;
use Modules\Finance\Domain\Ledger\AccountGroup;
use Modules\Finance\Domain\Ledger\NormalBalance;
use stdClass;

/**
 * Pengaturan pembukuan & pemetaan akun (PRD-007 §3, §5, PB-1). Hak akses (finance.ledger.manage tingkat Yayasan)
 * dicek pemanggil.
 *
 * - Tanggal mulai pembukuan wajib tanggal 1; bulan awal tahun buku 1–12 (OD-PB-04, bawaan Januari).
 * - Pemetaan: rekening kas TUNAI/BANK → akun kas/bank; kategori kas non-sistem → pendapatan (MASUK) atau
 *   beban/aset non-kas (KELUAR); jenis biaya → pendapatan. Kosong = akun bawaan. Rekening Titipan & kategori sistem
 *   tidak dipetakan (akunnya ditentukan jurnal otomatis).
 * - Perubahan tanggal mulai atau pemetaan menaikkan `config_version` → halaman menawarkan "Susun ulang jurnal".
 *
 * @phpstan-type Settings array{active: bool, start_date: string|null, fiscal_start_month: int, needs_rebuild: bool, rebuilding: bool, rebuild_done: int, rebuild_total: int, rebuilt_at: string|null}
 * @phpstan-type MappingRow array{id: string, name: string, group: string, kind: string|null, is_active: bool, account_id: string|null, default_account_id: string|null, mappable: bool}
 */
final class LedgerSetup
{
    public const KIND_CASH_ACCOUNT = 'rekening';

    public const KIND_CATEGORY = 'kategori';

    public const KIND_FEE_TYPE = 'jenis-biaya';

    private const MAPPED_TABLES = [
        self::KIND_CASH_ACCOUNT => 'fn_cash_accounts',
        self::KIND_CATEGORY => 'fn_cash_categories',
        self::KIND_FEE_TYPE => 'fn_fee_types',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ChartOfAccounts $accounts,
    ) {}

    /**
     * @return Settings
     */
    public function settings(string $tenantId): array
    {
        $row = DB::table('fn_ledger_settings')->where('tenant_id', $tenantId)->first();

        if ($row === null) {
            return ['active' => false, 'start_date' => null, 'fiscal_start_month' => 1, 'needs_rebuild' => false, 'rebuilding' => false, 'rebuild_done' => 0, 'rebuild_total' => 0, 'rebuilt_at' => null];
        }

        $start = $row->start_date === null ? null : substr((string) $row->start_date, 0, 10);

        return [
            'active' => $start !== null,
            'start_date' => $start,
            'fiscal_start_month' => (int) $row->fiscal_start_month,
            'needs_rebuild' => $start !== null && (int) $row->rebuilt_version < (int) $row->config_version,
            'rebuilding' => $row->rebuild_version !== null,
            'rebuild_done' => (int) $row->rebuild_done,
            'rebuild_total' => (int) $row->rebuild_total,
            'rebuilt_at' => $row->rebuilt_at === null ? null : CarbonImmutable::parse((string) $row->rebuilt_at)->toIso8601String(),
        ];
    }

    /**
     * Simpan tanggal mulai pembukuan & bulan awal tahun buku. Mengaktifkan pembukuan bila belum aktif.
     *
     * @throws FinanceException
     */
    public function setPeriod(string $tenantId, string $startDate, int $fiscalStartMonth): void
    {
        $start = $this->startDate($startDate);

        if ($fiscalStartMonth < 1 || $fiscalStartMonth > 12) {
            throw FinanceException::make('fiscal_start_month', 'Pilih bulan awal tahun buku.');
        }

        $this->accounts->ensureDefaults($tenantId);

        DB::transaction(function () use ($tenantId, $start, $fiscalStartMonth): void {
            DB::table('fn_ledger_settings')->insertOrIgnore(['tenant_id' => $tenantId, 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('fn_ledger_settings')->where('tenant_id', $tenantId)->lockForUpdate()->first();
            $previous = $row?->start_date === null ? null : substr((string) $row->start_date, 0, 10);
            $previousMonth = (int) ($row->fiscal_start_month ?? 1);

            if ($previous === $start && $previousMonth === $fiscalStartMonth) {
                return;
            }

            if ($row?->locked_through !== null) {
                throw FinanceException::make('start_date', 'Sudah ada periode yang ditutup buku. Buka semua kunci periode dulu sebelum mengubah tanggal mulai atau tahun buku.');
            }

            DB::table('fn_ledger_settings')->where('tenant_id', $tenantId)->update([
                'start_date' => $start,
                'fiscal_start_month' => $fiscalStartMonth,
                'config_version' => $previous === $start ? DB::raw('config_version') : DB::raw('config_version + 1'),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                FinanceAuditAction::LEDGER_SETTINGS_SET,
                FinanceAuditAction::SUBJECT_LEDGER_SETTINGS,
                $tenantId,
                ['tanggal_mulai' => [$previous, $start], 'bulan_awal_tahun_buku' => [$previousMonth, $fiscalStartMonth]],
                tenantId: $tenantId,
            );
        });
    }

    /**
     * Minta susun ulang semua jurnal otomatis walaupun pengaturan tidak berubah (mis. sesudah perbaikan data).
     *
     * @throws FinanceException
     */
    public function requestRebuild(string $tenantId): void
    {
        $updated = DB::table('fn_ledger_settings')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('start_date')
            ->update(['config_version' => DB::raw('config_version + 1'), 'updated_at' => now()]);

        if ($updated === 0) {
            throw FinanceException::make('form', 'Atur tanggal mulai pembukuan dulu.');
        }
    }

    /**
     * Daftar yang bisa dipetakan per jenis, dengan akun terpasang dan akun bawaannya.
     *
     * @return array{rekening: list<MappingRow>, kategori: list<MappingRow>, 'jenis-biaya': list<MappingRow>}
     */
    public function mappings(string $tenantId): array
    {
        $keyed = $this->accounts->keyed($tenantId);
        $cashAccounts = [];

        foreach (DB::table('fn_cash_accounts as a')
            ->leftJoin('organizations as o', 'o.id', '=', 'a.organization_id')
            ->where('a.tenant_id', $tenantId)
            ->orderByRaw('a.organization_id IS NOT NULL')
            ->orderBy('o.name')
            ->orderByRaw("CASE a.kind WHEN 'TUNAI' THEN 0 WHEN 'BANK' THEN 1 ELSE 2 END")
            ->orderBy('a.name')
            ->get(['a.id', 'a.name', 'a.kind', 'a.is_active', 'a.ledger_account_id', 'o.name as organization']) as $row) {
            $kind = CashAccountKind::from((string) $row->kind);
            $cashAccounts[] = [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'group' => $row->organization === null ? 'Kas Yayasan' : (string) $row->organization,
                'kind' => $kind->value,
                'is_active' => (bool) $row->is_active,
                'account_id' => $kind === CashAccountKind::Custody || $row->ledger_account_id === null ? null : (string) $row->ledger_account_id,
                'default_account_id' => $keyed[match ($kind) {
                    CashAccountKind::Cash => ChartOfAccounts::KEY_CASH,
                    CashAccountKind::Bank => ChartOfAccounts::KEY_BANK,
                    CashAccountKind::Custody => ChartOfAccounts::KEY_SAVINGS_CASH,
                }] ?? null,
                'mappable' => $kind !== CashAccountKind::Custody,
            ];
        }

        $categories = [];

        foreach (DB::table('fn_cash_categories')
            ->where('tenant_id', $tenantId)
            ->where('is_system', false)
            ->orderByRaw("direction = 'KELUAR'")
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'direction', 'is_active', 'ledger_account_id']) as $row) {
            $direction = CashDirection::from((string) $row->direction);
            $categories[] = [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'group' => $direction->label(),
                'kind' => $direction->value,
                'is_active' => (bool) $row->is_active,
                'account_id' => $row->ledger_account_id === null ? null : (string) $row->ledger_account_id,
                'default_account_id' => $keyed[$direction === CashDirection::In ? ChartOfAccounts::KEY_OTHER_INCOME : ChartOfAccounts::KEY_OTHER_EXPENSE] ?? null,
                'mappable' => true,
            ];
        }

        $feeTypes = [];

        foreach (DB::table('fn_fee_types')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'is_active', 'ledger_account_id']) as $row) {
            $feeTypes[] = [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'group' => 'Jenis biaya',
                'kind' => null,
                'is_active' => (bool) $row->is_active,
                'account_id' => $row->ledger_account_id === null ? null : (string) $row->ledger_account_id,
                'default_account_id' => $keyed[ChartOfAccounts::KEY_FEE_REVENUE] ?? null,
                'mappable' => true,
            ];
        }

        return [self::KIND_CASH_ACCOUNT => $cashAccounts, self::KIND_CATEGORY => $categories, self::KIND_FEE_TYPE => $feeTypes];
    }

    /**
     * Pasang (atau kosongkan = bawaan) akun untuk rekening kas / kategori kas / jenis biaya.
     *
     * @throws FinanceException
     */
    public function setMapping(string $tenantId, string $kind, string $itemId, ?string $accountId): void
    {
        $table = self::MAPPED_TABLES[$kind] ?? null;

        if ($table === null || ! Str::isUuid($itemId)) {
            throw FinanceException::make('form', 'Data yang dipetakan tidak ditemukan.');
        }

        $accountId = $accountId === null || $accountId === '' ? null : $accountId;

        DB::transaction(function () use ($tenantId, $kind, $table, $itemId, $accountId): void {
            $item = DB::table($table)->where('tenant_id', $tenantId)->where('id', $itemId)->lockForUpdate()->first();

            if ($item === null) {
                throw FinanceException::make('form', 'Data yang dipetakan tidak ditemukan.');
            }

            if ($kind === self::KIND_CASH_ACCOUNT && (string) $item->kind === CashAccountKind::Custody->value) {
                throw FinanceException::make('form', 'Rekening Titipan tabungan selalu memakai akun Kas titipan tabungan siswa.');
            }

            if ($kind === self::KIND_CATEGORY && (bool) $item->is_system) {
                throw FinanceException::make('form', 'Kategori otomatis tidak dipetakan; akunnya ditentukan jurnal otomatis.');
            }

            if ($accountId !== null) {
                $this->assertTarget($tenantId, $kind, $item, $accountId);
            }

            $previous = $item->ledger_account_id === null ? null : (string) $item->ledger_account_id;

            if ($previous === $accountId) {
                return;
            }

            DB::table($table)->where('id', $itemId)->update(['ledger_account_id' => $accountId]);
            DB::table('fn_ledger_settings')->where('tenant_id', $tenantId)->update(['config_version' => DB::raw('config_version + 1'), 'updated_at' => now()]);

            $codes = DB::table('fn_ledger_accounts')->whereIn('id', array_filter([$previous, $accountId]))->pluck('code', 'id');

            $this->audit->record(
                FinanceAuditAction::LEDGER_MAPPING_SET,
                FinanceAuditAction::SUBJECT_LEDGER_SETTINGS,
                $tenantId,
                ['jenis' => $kind, 'nama' => (string) $item->name, 'akun' => [$previous === null ? null : ($codes[$previous] ?? null), $accountId === null ? null : ($codes[$accountId] ?? null)]],
                tenantId: $tenantId,
            );
        });
    }

    /**
     * Akun yang boleh dipilih per jenis pemetaan: [jenis => list id].
     *
     * @return array{rekening: list<string>, 'kategori-MASUK': list<string>, 'kategori-KELUAR': list<string>, 'jenis-biaya': list<string>}
     */
    public function targets(string $tenantId): array
    {
        $targets = ['rekening' => [], 'kategori-MASUK' => [], 'kategori-KELUAR' => [], 'jenis-biaya' => []];

        foreach (DB::table('fn_ledger_accounts')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('is_header', false)
            ->orderBy('code')
            ->get(['id', 'account_group', 'normal_balance', 'is_cash', 'system_key']) as $account) {
            foreach ($this->kindsFor($account) as $kind) {
                $targets[$kind][] = (string) $account->id;
            }
        }

        return $targets;
    }

    /**
     * @return list<'rekening'|'kategori-MASUK'|'kategori-KELUAR'|'jenis-biaya'>
     */
    private function kindsFor(stdClass $account): array
    {
        $group = (string) $account->account_group;
        $key = $account->system_key === null ? null : (string) $account->system_key;
        $credit = (string) $account->normal_balance === NormalBalance::Credit->value;

        if ((bool) $account->is_cash) {
            return $key === ChartOfAccounts::KEY_SAVINGS_CASH ? [] : ['rekening'];
        }

        return match (true) {
            $group === AccountGroup::Revenue->value && $credit => ['kategori-MASUK', 'jenis-biaya'],
            $group === AccountGroup::Expense->value => ['kategori-KELUAR'],
            $group === AccountGroup::Asset->value && ! in_array($key, ChartOfAccounts::SYSTEM_ONLY_KEYS, true) && ! $credit => ['kategori-KELUAR'],
            default => [],
        };
    }

    /**
     * @throws FinanceException
     */
    private function assertTarget(string $tenantId, string $kind, stdClass $item, string $accountId): void
    {
        $account = Str::isUuid($accountId)
            ? DB::table('fn_ledger_accounts')->where('tenant_id', $tenantId)->where('id', $accountId)->where('is_active', true)->where('is_header', false)->first(['id', 'account_group', 'normal_balance', 'is_cash', 'system_key'])
            : null;
        $wanted = $kind === self::KIND_CATEGORY ? 'kategori-'.((string) $item->direction) : $kind;

        if ($account === null || ! in_array($wanted, $this->kindsFor($account), true)) {
            throw FinanceException::make('account_id', match ($wanted) {
                'rekening' => 'Pilih akun kas/bank yang aktif.',
                'kategori-MASUK', 'jenis-biaya' => 'Pilih akun pendapatan yang aktif.',
                default => 'Pilih akun beban atau aset (bukan kas) yang aktif.',
            });
        }
    }

    /**
     * @throws FinanceException
     */
    private function startDate(string $value): string
    {
        $value = trim($value);
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $value, BusinessClock::timezone()) : null;

        if ($date === null || $date->format('Y-m-d') !== $value) {
            throw FinanceException::make('start_date', 'Isi tanggal mulai pembukuan yang valid.');
        }

        if ($date->day !== 1) {
            throw FinanceException::make('start_date', 'Tanggal mulai pembukuan harus tanggal 1 (awal bulan).');
        }

        if ($date->year < 2000) {
            throw FinanceException::make('start_date', 'Isi tanggal mulai pembukuan yang valid.');
        }

        if ($date->greaterThan(BusinessClock::today()->addYear())) {
            throw FinanceException::make('start_date', 'Tanggal mulai pembukuan paling lambat satu tahun dari hari ini.');
        }

        return $value;
    }
}
