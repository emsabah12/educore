<?php

namespace Modules\Finance\Application\Cash;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Domain\Cash\CashDirection;
use Modules\Finance\Domain\Fee\Exceptions\FinanceException;
use stdClass;

/**
 * Kategori kas (PRD-004 §3, KS-1): pemasukan/pengeluaran per yayasan. Hak akses (finance.settings.manage
 * tingkat Yayasan) dicek pemanggil.
 *
 * Aturan [ASUMSI KS-1, mengikuti jenis biaya FN-1a]: kode huruf besar/angka/"-" 2–30 karakter, unik per yayasan,
 * tidak bisa diubah; nama 1–100; jenis (Masuk/Keluar) tetap; tidak dihapus (nonaktif = tidak bisa dipilih lagi).
 * Kategori sistem (mis. "Pembayaran siswa", KS-2) tidak bisa diubah, dinonaktifkan, atau dipilih manual.
 *
 * @phpstan-type CategoryRow array{id: string, code: string, name: string, direction: string, is_system: bool, is_active: bool}
 */
final class ManageCashCategories
{
    public const CODE_PATTERN = '/^[A-Z0-9][A-Z0-9-]{1,29}$/';

    public const NAME_MAX_LENGTH = 100;

    /** Kategori sistem untuk penerimaan pembayaran siswa dari kasir (KS-2). */
    public const SYSTEM_STUDENT_PAYMENTS = 'PEMBAYARAN-SISWA';

    /** Kategori bawaan saat yayasan pertama kali membuka kas: [kode, nama, arah, sistem]. */
    private const DEFAULTS = [
        [self::SYSTEM_STUDENT_PAYMENTS, 'Pembayaran siswa', CashDirection::In, true],
        ['BOS', 'Dana BOS', CashDirection::In, false],
        ['DONASI', 'Donasi / infak', CashDirection::In, false],
        ['MASUK-LAIN', 'Pemasukan lain-lain', CashDirection::In, false],
        ['UTILITAS', 'Listrik, air & internet', CashDirection::Out, false],
        ['ATK', 'ATK & perlengkapan', CashDirection::Out, false],
        ['KEGIATAN', 'Kegiatan', CashDirection::Out, false],
        ['PEMELIHARAAN', 'Pemeliharaan', CashDirection::Out, false],
        ['HONOR', 'Honor non-gaji', CashDirection::Out, false],
        ['KELUAR-LAIN', 'Pengeluaran lain-lain', CashDirection::Out, false],
    ];

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Isi kategori bawaan bila yayasan belum punya kategori kas sama sekali (aman diulang).
     */
    public function ensureDefaults(string $tenantId): void
    {
        if (DB::table('fn_cash_categories')->where('tenant_id', $tenantId)->exists()) {
            return;
        }

        $now = now();
        $rows = [];

        foreach (self::DEFAULTS as [$code, $name, $direction, $system]) {
            $rows[] = [
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'code' => $code, 'name' => $name,
                'direction' => $direction->value, 'is_system' => $system, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        DB::table('fn_cash_categories')->insertOrIgnore($rows);
    }

    /**
     * Semua kategori, urut: arah (Masuk dulu), aktif dulu, nama.
     *
     * @return list<CategoryRow>
     */
    public function all(string $tenantId): array
    {
        $rows = [];

        foreach (DB::table('fn_cash_categories')
            ->where('tenant_id', $tenantId)
            ->orderByRaw("direction = 'KELUAR'")
            ->orderByDesc('is_active')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'direction', 'is_system', 'is_active']) as $row) {
            $rows[] = [
                'id' => (string) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'direction' => (string) $row->direction,
                'is_system' => (bool) $row->is_system,
                'is_active' => (bool) $row->is_active,
            ];
        }

        return $rows;
    }

    /**
     * @throws FinanceException
     */
    public function create(string $tenantId, string $code, string $name, CashDirection $direction): string
    {
        $code = Str::upper(trim($code));

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw FinanceException::make('code', 'Kode hanya boleh berisi huruf, angka, dan tanda "-", panjang 2–30 karakter.');
        }

        $name = $this->name($name);
        $id = (string) Str::uuid7();

        try {
            DB::transaction(function () use ($tenantId, $code, $name, $direction, $id): void {
                if (DB::table('fn_cash_categories')->where('tenant_id', $tenantId)->where('code', $code)->exists()) {
                    throw FinanceException::make('code', "Kode {$code} sudah dipakai kategori lain.");
                }

                DB::table('fn_cash_categories')->insert([
                    'id' => $id, 'tenant_id' => $tenantId, 'code' => $code, 'name' => $name, 'direction' => $direction->value,
                    'is_system' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);

                $this->audit->record(
                    FinanceAuditAction::CASH_CATEGORY_CREATED,
                    FinanceAuditAction::SUBJECT_CASH_CATEGORY,
                    $id,
                    ['kode' => $code, 'nama' => $name, 'jenis' => $direction->value],
                    tenantId: $tenantId,
                );
            });
        } catch (UniqueConstraintViolationException) {
            throw FinanceException::make('code', "Kode {$code} sudah dipakai kategori lain.");
        }

        return $id;
    }

    /**
     * @throws FinanceException
     */
    public function rename(string $tenantId, string $categoryId, string $name): void
    {
        $name = $this->name($name);

        DB::transaction(function () use ($tenantId, $categoryId, $name): void {
            $category = $this->lockEditable($tenantId, $categoryId);

            if ($category->name === $name) {
                return;
            }

            DB::table('fn_cash_categories')->where('id', $categoryId)->update(['name' => $name, 'updated_at' => now()]);

            $this->audit->record(
                FinanceAuditAction::CASH_CATEGORY_UPDATED,
                FinanceAuditAction::SUBJECT_CASH_CATEGORY,
                $categoryId,
                ['kode' => (string) $category->code, 'nama' => [(string) $category->name, $name]],
                tenantId: $tenantId,
            );
        });
    }

    /**
     * @throws FinanceException
     */
    public function setActive(string $tenantId, string $categoryId, bool $active): void
    {
        DB::transaction(function () use ($tenantId, $categoryId, $active): void {
            $category = $this->lockEditable($tenantId, $categoryId);

            if ((bool) $category->is_active === $active) {
                return;
            }

            DB::table('fn_cash_categories')->where('id', $categoryId)->update(['is_active' => $active, 'updated_at' => now()]);

            $this->audit->record(
                $active ? FinanceAuditAction::CASH_CATEGORY_ACTIVATED : FinanceAuditAction::CASH_CATEGORY_DEACTIVATED,
                FinanceAuditAction::SUBJECT_CASH_CATEGORY,
                $categoryId,
                ['kode' => (string) $category->code],
                tenantId: $tenantId,
            );
        });
    }

    /**
     * @throws FinanceException
     */
    private function lockEditable(string $tenantId, string $categoryId): stdClass
    {
        $category = Str::isUuid($categoryId)
            ? DB::table('fn_cash_categories')->where('tenant_id', $tenantId)->where('id', $categoryId)->lockForUpdate()->first()
            : null;

        if ($category === null) {
            throw FinanceException::make('form', 'Kategori tidak ditemukan.');
        }

        if ((bool) $category->is_system) {
            throw FinanceException::make('form', 'Kategori sistem tidak bisa diubah.');
        }

        return $category;
    }

    /**
     * @throws FinanceException
     */
    private function name(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw FinanceException::make('name', 'Nama wajib diisi, maksimal '.self::NAME_MAX_LENGTH.' karakter.');
        }

        return $name;
    }
}
