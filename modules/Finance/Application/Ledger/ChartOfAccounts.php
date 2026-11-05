<?php

namespace Modules\Finance\Application\Ledger;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Application\Cash\ManageCashCategories;
use Modules\Finance\Domain\Fee\Exceptions\FinanceException;
use Modules\Finance\Domain\Ledger\AccountGroup;
use Modules\Finance\Domain\Ledger\CashFlowActivity;
use Modules\Finance\Domain\Ledger\NormalBalance;
use stdClass;

/**
 * COA per yayasan (PRD-007 §3.2, PB-1). Hak akses (finance.ledger.manage tingkat Yayasan) dicek pemanggil.
 *
 * Aturan [ASUMSI PB-1]:
 * - kode 2–20 karakter (angka, ".", "-", diawali angka), unik, tidak bisa diubah; nama 1–100;
 * - golongan, jenis (judul/posting), saldo normal, dan "akun kas" tetap sesudah dibuat; nama, induk, "dengan
 *   pembatasan", dan aktivitas arus kas boleh diubah;
 * - akun judul hanya satu tingkat (tanpa induk); induk akun posting = akun judul golongan yang sama;
 * - tidak dihapus; nonaktif ditolak untuk akun yang dipakai jurnal otomatis (`system_key`), akun yang dipetakan,
 *   dan akun judul yang masih punya akun aktif.
 *
 * @phpstan-type AccountRow array{id: string, code: string, name: string, group: string, is_header: bool, parent_id: string|null, normal_balance: string, is_cash: bool, is_restricted: bool, cash_flow_activity: string|null, system_key: string|null, is_locked: bool, is_active: bool}
 */
final class ChartOfAccounts
{
    public const CODE_PATTERN = '/^[0-9][0-9.\-]{1,19}$/';

    public const NAME_MAX_LENGTH = 100;

    /** Kas tunai: bawaan rekening kas jenis TUNAI. */
    public const KEY_CASH = 'CASH';

    /** Bank: bawaan rekening kas jenis BANK. */
    public const KEY_BANK = 'BANK';

    /** Kas titipan tabungan siswa (rekening kas jenis TITIPAN, PRD-005). */
    public const KEY_SAVINGS_CASH = 'SAVINGS_CASH';

    public const KEY_RECEIVABLE = 'RECEIVABLE';

    /** Rekening antar-kantor untuk mutasi antar-kas (PRD-007 §3.1). */
    public const KEY_INTER_OFFICE = 'INTER_OFFICE';

    public const KEY_SAVINGS_LIABILITY = 'SAVINGS_LIABILITY';

    public const KEY_NET_ASSETS_FREE = 'NET_ASSETS_FREE';

    public const KEY_NET_ASSETS_RESTRICTED = 'NET_ASSETS_RESTRICTED';

    /** Bawaan semua jenis biaya. */
    public const KEY_FEE_REVENUE = 'FEE_REVENUE';

    public const KEY_DISCOUNT = 'DISCOUNT';

    /** Bawaan kategori kas MASUK yang belum dipetakan. */
    public const KEY_OTHER_INCOME = 'OTHER_INCOME';

    /** Bawaan kategori kas KELUAR yang belum dipetakan. */
    public const KEY_OTHER_EXPENSE = 'OTHER_EXPENSE';

    /** Akun yang hanya diisi jurnal otomatis (PRD-007 §6: tidak boleh dipakai jurnal manual). */
    public const SYSTEM_ONLY_KEYS = [self::KEY_SAVINGS_CASH, self::KEY_RECEIVABLE, self::KEY_INTER_OFFICE, self::KEY_SAVINGS_LIABILITY];

    /**
     * COA bawaan (PRD-007 §3.2): [kode, nama, golongan, judul, kode induk, kontra, akun kas, aktivitas, kunci].
     *
     * @var list<array{0: string, 1: string, 2: AccountGroup, 3: bool, 4: string|null, 5: bool, 6: bool, 7: CashFlowActivity|null, 8: string|null}>
     */
    private const DEFAULTS = [
        ['1-1000', 'Aset lancar', AccountGroup::Asset, true, null, false, false, null, null],
        ['1-1100', 'Kas tunai', AccountGroup::Asset, false, '1-1000', false, true, null, self::KEY_CASH],
        ['1-1200', 'Bank', AccountGroup::Asset, false, '1-1000', false, true, null, self::KEY_BANK],
        ['1-1300', 'Kas titipan tabungan siswa', AccountGroup::Asset, false, '1-1000', false, true, null, self::KEY_SAVINGS_CASH],
        ['1-1400', 'Piutang siswa', AccountGroup::Asset, false, '1-1000', false, false, CashFlowActivity::Operating, self::KEY_RECEIVABLE],
        ['1-1900', 'Aset lancar lain', AccountGroup::Asset, false, '1-1000', false, false, CashFlowActivity::Operating, null],
        ['1-2000', 'Aset tidak lancar', AccountGroup::Asset, true, null, false, false, null, null],
        ['1-2100', 'Tanah & bangunan', AccountGroup::Asset, false, '1-2000', false, false, CashFlowActivity::Investing, null],
        ['1-2200', 'Peralatan & inventaris', AccountGroup::Asset, false, '1-2000', false, false, CashFlowActivity::Investing, null],
        ['1-2900', 'Akumulasi penyusutan', AccountGroup::Asset, false, '1-2000', true, false, CashFlowActivity::Investing, null],
        ['1-9000', 'Antar-kantor', AccountGroup::Asset, true, null, false, false, null, null],
        ['1-9100', 'Rekening antar-kantor (RAK)', AccountGroup::Asset, false, '1-9000', false, false, CashFlowActivity::Financing, self::KEY_INTER_OFFICE],
        ['2-1000', 'Liabilitas jangka pendek', AccountGroup::Liability, true, null, false, false, null, null],
        ['2-1100', 'Utang titipan tabungan siswa', AccountGroup::Liability, false, '2-1000', false, false, CashFlowActivity::Operating, self::KEY_SAVINGS_LIABILITY],
        ['2-1900', 'Utang lain-lain', AccountGroup::Liability, false, '2-1000', false, false, CashFlowActivity::Financing, null],
        ['3-0000', 'Aset neto', AccountGroup::NetAssets, true, null, false, false, null, null],
        ['3-1000', 'Aset neto tanpa pembatasan', AccountGroup::NetAssets, false, '3-0000', false, false, null, self::KEY_NET_ASSETS_FREE],
        ['3-2000', 'Aset neto dengan pembatasan', AccountGroup::NetAssets, false, '3-0000', false, false, null, self::KEY_NET_ASSETS_RESTRICTED],
        ['4-0000', 'Pendapatan', AccountGroup::Revenue, true, null, false, false, null, null],
        ['4-1100', 'Pendapatan biaya pendidikan', AccountGroup::Revenue, false, '4-0000', false, false, CashFlowActivity::Operating, self::KEY_FEE_REVENUE],
        ['4-1900', 'Potongan & beasiswa', AccountGroup::Revenue, false, '4-0000', true, false, CashFlowActivity::Operating, self::KEY_DISCOUNT],
        ['4-2100', 'Pendapatan dana BOS', AccountGroup::Revenue, false, '4-0000', false, false, CashFlowActivity::Operating, null],
        ['4-3100', 'Donasi / infak', AccountGroup::Revenue, false, '4-0000', false, false, CashFlowActivity::Operating, null],
        ['4-9100', 'Pendapatan lain-lain', AccountGroup::Revenue, false, '4-0000', false, false, CashFlowActivity::Operating, self::KEY_OTHER_INCOME],
        ['5-0000', 'Beban', AccountGroup::Expense, true, null, false, false, null, null],
        ['5-1100', 'Beban listrik, air & internet', AccountGroup::Expense, false, '5-0000', false, false, CashFlowActivity::Operating, null],
        ['5-1200', 'Beban ATK & perlengkapan', AccountGroup::Expense, false, '5-0000', false, false, CashFlowActivity::Operating, null],
        ['5-1300', 'Beban kegiatan', AccountGroup::Expense, false, '5-0000', false, false, CashFlowActivity::Operating, null],
        ['5-1400', 'Beban pemeliharaan', AccountGroup::Expense, false, '5-0000', false, false, CashFlowActivity::Operating, null],
        ['5-1500', 'Beban honor non-gaji', AccountGroup::Expense, false, '5-0000', false, false, CashFlowActivity::Operating, null],
        ['5-1600', 'Beban gaji pegawai', AccountGroup::Expense, false, '5-0000', false, false, CashFlowActivity::Operating, null],
        ['5-1700', 'Beban penyusutan', AccountGroup::Expense, false, '5-0000', false, false, CashFlowActivity::Operating, null],
        ['5-9100', 'Beban lain-lain', AccountGroup::Expense, false, '5-0000', false, false, CashFlowActivity::Operating, self::KEY_OTHER_EXPENSE],
    ];

    /** Pemetaan bawaan kategori kas bawaan (PRD-004 KS-1) → kode akun. */
    private const CATEGORY_DEFAULTS = [
        'BOS' => '4-2100',
        'DONASI' => '4-3100',
        'MASUK-LAIN' => '4-9100',
        'UTILITAS' => '5-1100',
        'ATK' => '5-1200',
        'KEGIATAN' => '5-1300',
        'PEMELIHARAAN' => '5-1400',
        'HONOR' => '5-1500',
        'KELUAR-LAIN' => '5-9100',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ManageCashCategories $categories,
    ) {}

    /**
     * Buat COA bawaan bila yayasan belum punya akun sama sekali, sekaligus memetakan kategori kas bawaan. Aman diulang
     * dan dijalankan bersamaan.
     */
    public function ensureDefaults(string $tenantId): void
    {
        if (DB::table('fn_ledger_accounts')->where('tenant_id', $tenantId)->exists()) {
            return;
        }

        DB::transaction(function () use ($tenantId): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["finance-ledger-coa:{$tenantId}"]);

            if (DB::table('fn_ledger_accounts')->where('tenant_id', $tenantId)->exists()) {
                return;
            }

            $now = now();
            $ids = [];

            foreach (self::DEFAULTS as [$code, $name, $group, $header, $parent, $contra, $cash, $activity, $key]) {
                $ids[$code] = (string) Str::uuid7();
                $normal = $group->normalBalance();

                DB::table('fn_ledger_accounts')->insert([
                    'id' => $ids[$code],
                    'tenant_id' => $tenantId,
                    'code' => $code,
                    'name' => $name,
                    'account_group' => $group->value,
                    'is_header' => $header,
                    'parent_id' => $parent === null ? null : ($ids[$parent] ?? null),
                    'normal_balance' => ($contra ? ($normal === NormalBalance::Debit ? NormalBalance::Credit : NormalBalance::Debit) : $normal)->value,
                    'is_cash' => $cash,
                    'is_restricted' => $key === self::KEY_NET_ASSETS_RESTRICTED,
                    'cash_flow_activity' => $activity?->value,
                    'system_key' => $key,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $this->categories->ensureDefaults($tenantId);

            foreach (self::CATEGORY_DEFAULTS as $categoryCode => $accountCode) {
                $target = $ids[$accountCode] ?? null;

                if ($target === null) {
                    continue;
                }

                DB::table('fn_cash_categories')
                    ->where('tenant_id', $tenantId)
                    ->where('code', $categoryCode)
                    ->whereNull('ledger_account_id')
                    ->update(['ledger_account_id' => $target]);
            }
        });
    }

    /**
     * Semua akun, urut kode.
     *
     * @return list<AccountRow>
     */
    public function all(string $tenantId): array
    {
        $rows = [];

        foreach (DB::table('fn_ledger_accounts')->where('tenant_id', $tenantId)->orderBy('code')->get() as $row) {
            $rows[] = $this->present($row);
        }

        return $rows;
    }

    /**
     * Id akun berkunci (`system_key`) → id.
     *
     * @return array<string, string>
     */
    public function keyed(string $tenantId): array
    {
        $ids = [];

        foreach (DB::table('fn_ledger_accounts')->where('tenant_id', $tenantId)->whereNotNull('system_key')->get(['id', 'system_key']) as $row) {
            $ids[(string) $row->system_key] = (string) $row->id;
        }

        return $ids;
    }

    /**
     * Akun yang boleh dipakai jurnal manual / baris saldo awal (PRD-007 §5–§6): akun posting aktif, bukan akun kas/bank,
     * bukan akun yang hanya diisi jurnal otomatis; untuk saldo awal juga bukan Aset neto tanpa pembatasan (penyeimbang).
     *
     * @return list<array{value: string, label: string}>
     */
    public function manualOptions(string $tenantId, bool $forOpening): array
    {
        $options = [];

        foreach ($this->manualQuery($tenantId, $forOpening)->orderBy('code')->get(['id', 'code', 'name']) as $row) {
            $options[] = ['value' => (string) $row->id, 'label' => "{$row->code} · {$row->name}"];
        }

        return $options;
    }

    /**
     * @throws FinanceException akun tidak boleh dipakai
     */
    public function assertManualAccount(string $tenantId, string $accountId, bool $forOpening, string $field, string $prefix = ''): void
    {
        if (! Str::isUuid($accountId) || ! $this->manualQuery($tenantId, $forOpening)->where('id', $accountId)->exists()) {
            throw FinanceException::make($field, $prefix.($forOpening
                ? 'Pilih akun aktif selain akun kas/bank, akun otomatis, dan Aset neto tanpa pembatasan (diisi otomatis sebagai selisih).'
                : 'Pilih akun aktif selain akun kas/bank dan akun yang hanya diisi jurnal otomatis. Uang masuk/keluar dicatat di Kas.'));
        }
    }

    /**
     * Lembaga baris jurnal: kosong = Yayasan, selain itu node LEMBAGA aktif yayasan ini.
     *
     * @throws FinanceException
     */
    public function ledgerOrganization(string $tenantId, ?string $organizationId, string $field, string $prefix = ''): ?string
    {
        if ($organizationId === null || $organizationId === '' || $organizationId === 'yayasan') {
            return null;
        }

        $found = Str::isUuid($organizationId) && DB::table('organizations')
            ->where('tenant_id', $tenantId)
            ->where('id', $organizationId)
            ->where('type', OrganizationType::Lembaga->value)
            ->where('status', OrganizationStatus::Active->value)
            ->exists();

        if (! $found) {
            throw FinanceException::make($field, $prefix.'Pilih lembaga yang aktif (atau Yayasan).');
        }

        return $organizationId;
    }

    private function manualQuery(string $tenantId, bool $forOpening): Builder
    {
        $excluded = $forOpening ? [...self::SYSTEM_ONLY_KEYS, self::KEY_NET_ASSETS_FREE] : self::SYSTEM_ONLY_KEYS;

        return DB::table('fn_ledger_accounts')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('is_header', false)
            ->where('is_cash', false)
            ->where(fn (Builder $query) => $query->whereNull('system_key')->orWhereNotIn('system_key', $excluded));
    }

    /**
     * @param  array{code: string, name: string, group: AccountGroup, is_header: bool, parent_id: string|null, contra: bool, is_cash: bool, is_restricted: bool, cash_flow_activity: CashFlowActivity|null}  $input
     *
     * @throws FinanceException
     */
    public function create(string $tenantId, array $input): string
    {
        $code = trim($input['code']);

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw FinanceException::make('code', 'Kode akun 2–20 karakter: angka, titik, atau tanda "-", diawali angka (mis. 5-1800).');
        }

        $name = $this->name($input['name']);
        $group = $input['group'];
        $header = $input['is_header'];
        $cash = ! $header && $input['is_cash'];

        if ($cash && $group !== AccountGroup::Asset) {
            throw FinanceException::make('is_cash', 'Hanya akun aset yang bisa ditandai sebagai akun kas/bank.');
        }

        $restricted = ! $header && $input['is_restricted'];

        if ($restricted && ! in_array($group, [AccountGroup::Revenue, AccountGroup::NetAssets], true)) {
            throw FinanceException::make('is_restricted', 'Tanda "dengan pembatasan" hanya untuk akun pendapatan atau aset neto.');
        }

        $normal = $group->normalBalance();

        if (! $header && $input['contra']) {
            $normal = $normal === NormalBalance::Debit ? NormalBalance::Credit : NormalBalance::Debit;
        }

        $activity = ($header || $cash) ? null : $input['cash_flow_activity'];
        $id = (string) Str::uuid7();

        try {
            DB::transaction(function () use ($tenantId, $id, $code, $name, $group, $header, $cash, $restricted, $normal, $activity, $input): void {
                $parentId = $header ? null : $this->validParent($tenantId, $input['parent_id'], $group);

                if (DB::table('fn_ledger_accounts')->where('tenant_id', $tenantId)->where('code', $code)->exists()) {
                    throw FinanceException::make('code', "Kode {$code} sudah dipakai akun lain.");
                }

                DB::table('fn_ledger_accounts')->insert([
                    'id' => $id,
                    'tenant_id' => $tenantId,
                    'code' => $code,
                    'name' => $name,
                    'account_group' => $group->value,
                    'is_header' => $header,
                    'parent_id' => $parentId,
                    'normal_balance' => $normal->value,
                    'is_cash' => $cash,
                    'is_restricted' => $restricted,
                    'cash_flow_activity' => $activity?->value,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->audit->record(
                    FinanceAuditAction::LEDGER_ACCOUNT_CREATED,
                    FinanceAuditAction::SUBJECT_LEDGER_ACCOUNT,
                    $id,
                    ['kode' => $code, 'nama' => $name, 'golongan' => $group->value, 'judul' => $header, 'saldo_normal' => $normal->value, 'akun_kas' => $cash],
                    tenantId: $tenantId,
                );
            });
        } catch (UniqueConstraintViolationException) {
            throw FinanceException::make('code', "Kode {$code} sudah dipakai akun lain.");
        }

        return $id;
    }

    /**
     * Ubah nama, induk, "dengan pembatasan", dan aktivitas arus kas.
     *
     * @param  array{name: string, parent_id: string|null, is_restricted: bool, cash_flow_activity: CashFlowActivity|null}  $input
     *
     * @throws FinanceException
     */
    public function update(string $tenantId, string $accountId, array $input): void
    {
        $name = $this->name($input['name']);

        DB::transaction(function () use ($tenantId, $accountId, $input, $name): void {
            $account = $this->lock($tenantId, $accountId);
            $group = AccountGroup::from((string) $account->account_group);
            $header = (bool) $account->is_header;
            $parentId = $header ? null : $this->validParent($tenantId, $input['parent_id'], $group);
            $restricted = ! $header && $input['is_restricted'];

            if ($restricted && ! in_array($group, [AccountGroup::Revenue, AccountGroup::NetAssets], true)) {
                throw FinanceException::make('is_restricted', 'Tanda "dengan pembatasan" hanya untuk akun pendapatan atau aset neto.');
            }

            if ((string) $account->system_key === self::KEY_NET_ASSETS_RESTRICTED) {
                $restricted = true;
            } elseif ((string) $account->system_key === self::KEY_NET_ASSETS_FREE) {
                $restricted = false;
            }

            $activity = ($header || (bool) $account->is_cash) ? null : $input['cash_flow_activity']?->value;
            $values = ['name' => $name, 'parent_id' => $parentId, 'is_restricted' => $restricted, 'cash_flow_activity' => $activity];
            $changes = [];

            foreach ($values as $field => $value) {
                if ($account->{$field} !== $value) {
                    $changes[$field] = [$account->{$field}, $value];
                }
            }

            if ($changes === []) {
                return;
            }

            DB::table('fn_ledger_accounts')->where('id', $accountId)->update($values + ['updated_at' => now()]);

            $this->audit->record(
                FinanceAuditAction::LEDGER_ACCOUNT_UPDATED,
                FinanceAuditAction::SUBJECT_LEDGER_ACCOUNT,
                $accountId,
                ['kode' => (string) $account->code] + $changes,
                tenantId: $tenantId,
            );
        });
    }

    /**
     * @throws FinanceException
     */
    public function setActive(string $tenantId, string $accountId, bool $active): void
    {
        DB::transaction(function () use ($tenantId, $accountId, $active): void {
            $account = $this->lock($tenantId, $accountId);

            if ((bool) $account->is_active === $active) {
                return;
            }

            if (! $active) {
                $this->assertCanDeactivate($tenantId, $account);
            } elseif ($account->parent_id !== null && ! (bool) DB::table('fn_ledger_accounts')->where('id', $account->parent_id)->value('is_active')) {
                throw FinanceException::make('form', 'Aktifkan dulu akun induknya.');
            }

            DB::table('fn_ledger_accounts')->where('id', $accountId)->update(['is_active' => $active, 'updated_at' => now()]);

            $this->audit->record(
                $active ? FinanceAuditAction::LEDGER_ACCOUNT_ACTIVATED : FinanceAuditAction::LEDGER_ACCOUNT_DEACTIVATED,
                FinanceAuditAction::SUBJECT_LEDGER_ACCOUNT,
                $accountId,
                ['kode' => (string) $account->code, 'nama' => (string) $account->name],
                tenantId: $tenantId,
            );
        });
    }

    /**
     * @throws FinanceException
     */
    private function assertCanDeactivate(string $tenantId, stdClass $account): void
    {
        if ($account->system_key !== null) {
            throw FinanceException::make('form', 'Akun ini dipakai jurnal otomatis, jadi tidak bisa dinonaktifkan.');
        }

        if ((bool) $account->is_header && DB::table('fn_ledger_accounts')->where('parent_id', $account->id)->where('is_active', true)->exists()) {
            throw FinanceException::make('form', 'Nonaktifkan dulu akun-akun di bawah akun judul ini.');
        }

        foreach (['fn_cash_accounts' => 'rekening kas', 'fn_cash_categories' => 'kategori kas', 'fn_fee_types' => 'jenis biaya'] as $table => $label) {
            if (DB::table($table)->where('tenant_id', $tenantId)->where('ledger_account_id', $account->id)->exists()) {
                throw FinanceException::make('form', "Akun ini masih dipetakan ke {$label}. Ubah pemetaannya dulu.");
            }
        }
    }

    /**
     * @throws FinanceException
     */
    private function validParent(string $tenantId, ?string $parentId, AccountGroup $group): ?string
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }

        $parent = Str::isUuid($parentId)
            ? DB::table('fn_ledger_accounts')->where('tenant_id', $tenantId)->where('id', $parentId)->first(['id', 'account_group', 'is_header', 'is_active'])
            : null;

        if ($parent === null || ! (bool) $parent->is_header || (string) $parent->account_group !== $group->value || ! (bool) $parent->is_active) {
            throw FinanceException::make('parent_id', 'Induk harus akun judul aktif dengan golongan yang sama.');
        }

        return (string) $parent->id;
    }

    /**
     * @throws FinanceException
     */
    private function lock(string $tenantId, string $accountId): stdClass
    {
        $account = Str::isUuid($accountId)
            ? DB::table('fn_ledger_accounts')->where('tenant_id', $tenantId)->where('id', $accountId)->lockForUpdate()->first()
            : null;

        if ($account === null) {
            throw FinanceException::make('form', 'Akun tidak ditemukan.');
        }

        return $account;
    }

    /**
     * @throws FinanceException
     */
    private function name(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw FinanceException::make('name', 'Nama akun wajib diisi, maksimal 100 karakter.');
        }

        return $name;
    }

    /**
     * @return AccountRow
     */
    private function present(stdClass $row): array
    {
        $key = $row->system_key === null ? null : (string) $row->system_key;

        return [
            'id' => (string) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'group' => (string) $row->account_group,
            'is_header' => (bool) $row->is_header,
            'parent_id' => $row->parent_id === null ? null : (string) $row->parent_id,
            'normal_balance' => (string) $row->normal_balance,
            'is_cash' => (bool) $row->is_cash,
            'is_restricted' => (bool) $row->is_restricted,
            'cash_flow_activity' => $row->cash_flow_activity === null ? null : (string) $row->cash_flow_activity,
            'system_key' => $key,
            'is_locked' => $key !== null && in_array($key, self::SYSTEM_ONLY_KEYS, true),
            'is_active' => (bool) $row->is_active,
        ];
    }
}
