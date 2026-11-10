<?php

namespace Modules\Academic\Application\Grading;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Domain\Grading\Exceptions\GradingException;
use Modules\Academic\Domain\Grading\ReportItem;
use Modules\Core\Application\Audit\AuditLogger;

/**
 * Butir rapor non-akademik per lembaga (PRD-002 §22.6 AC-8d): kegiatan ekstrakurikuler dan aspek
 * kepribadian (dengan keterangan otomatis per huruf predikat, OD owner 2026-11-11).
 *
 * Aturan [ASUMSI disetujui owner 2026-11-11]:
 * - Nama wajib (maks. 60), unik per lembaga & jenis (tanpa beda huruf besar/kecil); urutan 0–999.
 * - Keterangan kepribadian per huruf (huruf 1–2 huruf besar, teks maks. 100; kosong = tanpa keterangan).
 * - Hapus hanya bila belum pernah dinilai; selain itu nonaktifkan (tidak tampil untuk diisi, tetap
 *   tampil di rapor kelas yang sudah menilainya).
 *
 * Hak akses (academic.classes.manage di lembaga) dicek pemanggil.
 *
 * @phpstan-type ItemRow array{id: string, kind: string, name: string, sort_order: int, descriptions: array<string, string>, is_active: bool, used: bool}
 */
final class ReportItems
{
    public const NAME_MAX = 60;

    public const DESCRIPTION_MAX = 100;

    public const ORDER_MAX = 999;

    /**
     * Contoh 6 aspek kepribadian seperti rapor madrasah owner, dengan keterangan per huruf skala 5 tingkat.
     *
     * @var array<string, array<string, string>>
     */
    public const PERSONALITY_EXAMPLES = [
        'Kelakuan' => [
            'A' => 'Kepribadian sangat baik, sopan, dan jujur',
            'B' => 'Kepribadian baik, sopan, dan jujur',
            'C' => 'Cukup sopan; perlu dibina kejujurannya',
            'D' => 'Perlu bimbingan dalam bersikap sopan dan jujur',
            'E' => 'Perlu pembinaan khusus dalam bersikap',
        ],
        'Kerajinan' => [
            'A' => 'Sangat aktif dalam semua kegiatan madrasah',
            'B' => 'Aktif dalam semua kegiatan madrasah',
            'C' => 'Cukup aktif dalam kegiatan madrasah',
            'D' => 'Kurang aktif dalam kegiatan madrasah',
            'E' => 'Perlu dorongan untuk aktif dalam kegiatan',
        ],
        'Kerapihan' => [
            'A' => 'Pakaian dan catatan sangat rapi',
            'B' => 'Pakaian dan catatan rapi',
            'C' => 'Pakaian dan catatan cukup rapi',
            'D' => 'Pakaian dan catatan kurang rapi',
            'E' => 'Perlu bimbingan dalam kerapian',
        ],
        'Kebersihan' => [
            'A' => 'Pakaian sangat bersih dan sehat',
            'B' => 'Pakaian bersih dan sehat',
            'C' => 'Pakaian cukup bersih',
            'D' => 'Pakaian kurang bersih',
            'E' => 'Perlu bimbingan dalam kebersihan',
        ],
        'Kedisiplinan' => [
            'A' => 'Selalu tepat waktu dan sangat tertib',
            'B' => 'Tepat waktu dan sangat tertib',
            'C' => 'Cukup tepat waktu dan tertib',
            'D' => 'Sering terlambat; perlu dibina ketertibannya',
            'E' => 'Perlu pembinaan khusus kedisiplinan',
        ],
        'Ketaatan' => [
            'A' => 'Sangat taat pada peraturan yang ada',
            'B' => 'Taat pada peraturan yang ada',
            'C' => 'Cukup taat pada peraturan',
            'D' => 'Kurang taat pada peraturan',
            'E' => 'Perlu pembinaan dalam menaati peraturan',
        ],
    ];

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Semua butir lembaga, urut jenis (ekstrakurikuler dulu) → urutan → nama.
     *
     * @return list<ItemRow>
     */
    public function list(string $tenantId, string $organizationId): array
    {
        $used = DB::table('ac_report_item_grades')->where('organization_id', $organizationId)->distinct()->pluck('item_id')->map(fn (mixed $id): string => (string) $id)->all();
        $rows = [];

        foreach (ReportItem::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('organization_id', $organizationId)
            ->orderByRaw("kind = 'KEPRIBADIAN'")
            ->orderBy('sort_order')
            ->orderByRaw('lower(name)')
            ->get() as $item) {
            $rows[] = [
                'id' => $item->id,
                'kind' => $item->kind,
                'name' => $item->name,
                'sort_order' => $item->sort_order,
                'descriptions' => $item->descriptions ?? [],
                'is_active' => $item->is_active,
                'used' => in_array($item->id, $used, true),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $descriptions
     *
     * @throws GradingException
     */
    public function create(string $tenantId, string $organizationId, string $kind, string $name, int $sortOrder, array $descriptions): ReportItem
    {
        if (! in_array($kind, [ReportItem::EXTRACURRICULAR, ReportItem::PERSONALITY], true)) {
            throw GradingException::make('kind', 'Jenis butir tidak dikenal.');
        }

        $name = self::validName($name);
        self::assertOrder($sortOrder);
        $clean = $kind === ReportItem::PERSONALITY ? self::validDescriptions($descriptions) : null;

        return $this->guard($name, function () use ($tenantId, $organizationId, $kind, $name, $sortOrder, $clean): ReportItem {
            $this->assertUnique($organizationId, $kind, $name, null);

            $item = new ReportItem;
            $item->fill([
                'tenant_id' => $tenantId,
                'organization_id' => $organizationId,
                'kind' => $kind,
                'name' => $name,
                'sort_order' => $sortOrder,
                'descriptions' => $clean,
            ]);
            $item->save();
            $this->record(AcademicAuditAction::REPORT_ITEM_SAVED, $item, ['dibuat' => true]);

            return $item;
        });
    }

    /**
     * @param  array<string, mixed>  $descriptions
     * @return list<string> field yang berubah
     *
     * @throws GradingException
     */
    public function update(string $tenantId, string $itemId, string $name, int $sortOrder, array $descriptions, bool $active): array
    {
        $name = self::validName($name);
        self::assertOrder($sortOrder);

        return $this->guard($name, function () use ($tenantId, $itemId, $name, $sortOrder, $descriptions, $active): array {
            $item = $this->lock($tenantId, $itemId);
            $this->assertUnique($item->organization_id, $item->kind, $name, $item->id);

            $item->fill([
                'name' => $name,
                'sort_order' => $sortOrder,
                'descriptions' => $item->kind === ReportItem::PERSONALITY ? self::validDescriptions($descriptions) : null,
                'is_active' => $active,
            ]);
            $changed = array_keys($item->getDirty());

            if ($changed !== []) {
                $item->save();
                $this->record(AcademicAuditAction::REPORT_ITEM_SAVED, $item, ['field_diubah' => $changed]);
            }

            return $changed;
        });
    }

    /**
     * @throws GradingException
     */
    public function delete(string $tenantId, string $itemId): string
    {
        return DB::transaction(function () use ($tenantId, $itemId): string {
            $item = $this->lock($tenantId, $itemId);

            if (DB::table('ac_report_item_grades')->where('item_id', $item->id)->exists()) {
                throw GradingException::make('form', "{$item->name} sudah pernah dinilai, jadi tidak bisa dihapus. Nonaktifkan saja.");
            }

            $item->delete();
            $this->record(AcademicAuditAction::REPORT_ITEM_DELETED, $item, []);

            return $item->name;
        });
    }

    /**
     * Tambahkan 6 aspek kepribadian contoh yang belum ada (nama sama dilewati).
     *
     * @return int jumlah yang ditambahkan
     */
    public function addPersonalityExamples(string $tenantId, string $organizationId): int
    {
        $added = 0;
        $order = 1;

        foreach (self::PERSONALITY_EXAMPLES as $name => $descriptions) {
            $exists = ReportItem::query()->withoutGlobalScope('tenant')
                ->where('organization_id', $organizationId)
                ->where('kind', ReportItem::PERSONALITY)
                ->whereRaw('lower(name) = lower(?)', [$name])
                ->exists();

            if (! $exists) {
                $this->create($tenantId, $organizationId, ReportItem::PERSONALITY, $name, $order, $descriptions);
                $added++;
            }

            $order++;
        }

        return $added;
    }

    public function find(string $tenantId, string $itemId): ?ReportItem
    {
        return Str::isUuid($itemId)
            ? ReportItem::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($itemId)->first()
            : null;
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * @throws GradingException
     */
    private static function validName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw GradingException::make('name', 'Nama wajib diisi, maksimal '.self::NAME_MAX.' karakter.');
        }

        return $name;
    }

    /**
     * @throws GradingException
     */
    private static function assertOrder(int $sortOrder): void
    {
        if ($sortOrder < 0 || $sortOrder > self::ORDER_MAX) {
            throw GradingException::make('sort_order', 'Urutan harus angka 0–'.self::ORDER_MAX.'.');
        }
    }

    /**
     * @param  array<mixed>  $descriptions
     * @return array<string, string>|null
     *
     * @throws GradingException
     */
    private static function validDescriptions(array $descriptions): ?array
    {
        $clean = [];

        foreach ($descriptions as $letter => $text) {
            $letter = mb_strtoupper(trim((string) $letter));
            $text = is_string($text) ? trim((string) preg_replace('/\s+/u', ' ', $text)) : '';

            if ($text === '') {
                continue;
            }

            if (preg_match(GradingRules::LETTER_PATTERN, $letter) !== 1) {
                throw GradingException::make('descriptions', 'Huruf keterangan tidak dikenal.');
            }

            if (mb_strlen($text) > self::DESCRIPTION_MAX) {
                throw GradingException::make('descriptions', "Keterangan huruf {$letter} maksimal ".self::DESCRIPTION_MAX.' karakter.');
            }

            $clean[$letter] = $text;
        }

        return $clean === [] ? null : $clean;
    }

    private function assertUnique(string $organizationId, string $kind, string $name, ?string $exceptId): void
    {
        $query = ReportItem::query()->withoutGlobalScope('tenant')
            ->where('organization_id', $organizationId)
            ->where('kind', $kind)
            ->whereRaw('lower(name) = lower(?)', [$name]);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        if ($query->exists()) {
            throw GradingException::make('name', "{$name} sudah ada di daftar ini.");
        }
    }

    private function lock(string $tenantId, string $itemId): ReportItem
    {
        $item = Str::isUuid($itemId)
            ? ReportItem::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($itemId)->lockForUpdate()->first()
            : null;

        if (! $item instanceof ReportItem) {
            throw GradingException::make('form', 'Butir tidak ditemukan di yayasan ini.');
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function record(string $action, ReportItem $item, array $extra): void
    {
        $this->audit->record(
            $action,
            AcademicAuditAction::SUBJECT_REPORT_FORMAT,
            $item->id,
            ['jenis' => $item->kind === ReportItem::PERSONALITY ? 'kepribadian' : 'ekstrakurikuler', 'nama' => $item->name] + $extra,
            organizationId: $item->organization_id,
            tenantId: $item->tenant_id,
        );
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function guard(string $name, \Closure $callback): mixed
    {
        try {
            return DB::transaction($callback);
        } catch (UniqueConstraintViolationException) {
            throw GradingException::make('name', "{$name} baru saja ditambahkan orang lain. Muat ulang halaman.");
        }
    }
}
