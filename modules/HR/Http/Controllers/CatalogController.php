<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Catalog\CreateCatalogItem;
use Modules\HR\Application\Catalog\UpdateCatalogItem;
use Modules\HR\Domain\Catalog\CatalogItem;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Catalog\Exceptions\CatalogException;

/**
 * Halaman Katalog SDM (PRD-001 §6): jenis pegawai, status kepegawaian, jabatan, jenis dokumen (§15).
 *
 * Butuh `hr.catalog.manage` di tingkat Yayasan (role tenant-wide, lembaga kerja
 * Seluruh Yayasan), karena katalog berlaku untuk seluruh yayasan (PRD-001 §5.1).
 */
final class CatalogController
{
    private const CODE_RULE = 'regex:/^[A-Za-z0-9][A-Za-z0-9-]{1,29}$/';

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize();

        $kind = CatalogKind::tryFrom($request->string('jenis')->value()) ?? CatalogKind::EmploymentType;

        $items = $kind->query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (CatalogItem $item): array => [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'is_active' => $item->is_active,
                // Pilihan ya/tidak katalog (CatalogKind::flag); null bila katalog ini tidak punya.
                'flag' => $kind->flag() === null ? null : (bool) $item->getAttribute($kind->flag()['column']),
            ])
            ->values()
            ->all();

        $kinds = array_map(fn (CatalogKind $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
            'description' => $case->description(),
            'count' => $case->query()->count(),
        ], CatalogKind::cases());

        return Inertia::render('hr/catalog', [
            'kinds' => $kinds,
            'activeKind' => $kind->value,
            'flag' => $kind->flag() === null ? null : ['label' => $kind->flag()['label'], 'badge' => $kind->flag()['badge']],
            'items' => $items,
        ]);
    }

    public function store(Request $request, string $kind, CreateCatalogItem $createCatalogItem): RedirectResponse
    {
        $this->authorize();
        $catalogKind = $this->kind($kind);

        $request->validate([
            'code' => ['required', 'string', self::CODE_RULE],
            'name' => ['required', 'string', 'max:'.CreateCatalogItem::NAME_MAX_LENGTH],
            'flag' => ['nullable', 'boolean'],
        ], [
            'code.regex' => 'Kode hanya boleh berisi huruf, angka, dan tanda "-", panjang 2–30 karakter.',
        ], $this->attributes());

        $item = $this->attempt('code', fn (): CatalogItem => $createCatalogItem->handle(
            $this->tenantId(),
            $catalogKind,
            $request->string('code')->value(),
            $request->string('name')->value(),
            $catalogKind->flag() !== null && $request->boolean('flag'),
        ));

        return $this->done("{$item->name} berhasil ditambahkan.");
    }

    public function update(Request $request, string $kind, string $item, UpdateCatalogItem $updateCatalogItem): RedirectResponse
    {
        $this->authorize();
        $catalogItem = $this->find($this->kind($kind), $item);

        $request->validate([
            'name' => ['required', 'string', 'max:'.CreateCatalogItem::NAME_MAX_LENGTH],
            'flag' => ['nullable', 'boolean'],
        ], [], $this->attributes());

        $renamed = $this->attempt('name', fn (): CatalogItem => $updateCatalogItem->rename(
            $this->tenantId(),
            $catalogItem->kind(),
            $catalogItem->id,
            $request->string('name')->value(),
        ));

        // Pilihan ya/tidak katalog (masa berlaku jenis dokumen, honor per JP) ikut disimpan.
        if ($catalogItem->kind()->flag() !== null && $request->has('flag')) {
            $this->attempt('flag', fn (): CatalogItem => $updateCatalogItem->setFlag($this->tenantId(), $catalogItem->kind(), $catalogItem->id, $request->boolean('flag')));
        }

        return $this->done("{$renamed->name} disimpan.");
    }

    public function deactivate(string $kind, string $item, UpdateCatalogItem $updateCatalogItem): RedirectResponse
    {
        $this->authorize();
        $catalogItem = $this->find($this->kind($kind), $item);

        $this->attempt('form', fn (): CatalogItem => $updateCatalogItem->setActive($this->tenantId(), $catalogItem->kind(), $catalogItem->id, false));

        return $this->done("{$catalogItem->name} dinonaktifkan. Data lama yang memakainya tetap tersimpan.");
    }

    public function activate(string $kind, string $item, UpdateCatalogItem $updateCatalogItem): RedirectResponse
    {
        $this->authorize();
        $catalogItem = $this->find($this->kind($kind), $item);

        $this->attempt('form', fn (): CatalogItem => $updateCatalogItem->setActive($this->tenantId(), $catalogItem->kind(), $catalogItem->id, true));

        return $this->done("{$catalogItem->name} diaktifkan kembali.");
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * Katalog hanya dikelola di tingkat Yayasan: 403 bila tidak punya izinnya.
     */
    private function authorize(): void
    {
        $this->authorization->inspectTarget(HrAccess::CATALOG_MANAGE, null, null)->authorize();
    }

    private function kind(string $value): CatalogKind
    {
        return CatalogKind::tryFrom($value) ?? abort(404);
    }

    /**
     * Cari item di yayasan aktif (filter yayasan otomatis). Tidak ada → 404.
     */
    private function find(CatalogKind $kind, string $id): CatalogItem
    {
        $item = Str::isUuid($id) ? $kind->query()->find($id) : null;

        if (! $item instanceof CatalogItem) {
            abort(404);
        }

        return $item;
    }

    private function tenantId(): string
    {
        return $this->tenantContext->require()->tenantId;
    }

    /**
     * Jalankan service; pelanggaran aturan katalog ditampilkan sebagai pesan di field $field.
     *
     * @param  callable(): CatalogItem  $action
     */
    private function attempt(string $field, callable $action): CatalogItem
    {
        try {
            return $action();
        } catch (CatalogException $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    /**
     * @return array<string, string>
     */
    private function attributes(): array
    {
        return [
            'code' => 'kode',
            'name' => 'nama',
        ];
    }
}
