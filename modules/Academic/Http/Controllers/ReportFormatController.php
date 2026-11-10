<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Academic\Application\Authorization\AcademicAccess;
use Modules\Academic\Application\Grading\GradingRules;
use Modules\Academic\Application\Grading\GradingSetting;
use Modules\Academic\Application\Grading\ReportFormats;
use Modules\Academic\Application\Grading\ReportItems;
use Modules\Academic\Domain\Grading\Exceptions\GradingException;
use Modules\Academic\Domain\Grading\ReportItem;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Core\Domain\Tenancy\TenantContext;

/**
 * Halaman Format rapor per lembaga (PRD-002 §22.5 AC-8c) beserta daftar ekstrakurikuler &
 * kepribadian (§22.6 AC-8d). Pilihan lembaga = lembaga aktif yang boleh dikelola kelasnya
 * (academic.classes.manage: Operator Akademik lembaga itu & Admin Yayasan).
 */
final class ReportFormatController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuthorizationService $authorization,
    ) {}

    public function index(Request $request, ReportFormats $formats, ReportItems $items, GradingSetting $grading): Response
    {
        Gate::authorize(AcademicAccess::CLASSES_MANAGE);

        $organizations = $this->organizationOptions();
        $allowed = array_column($organizations, 'value');
        $requested = $request->query('lembaga');
        $organizationId = is_string($requested) && in_array($requested, $allowed, true) ? $requested : ($allowed[0] ?? null);
        $tenantId = $this->tenantId();

        return Inertia::render('academic/grades/report-format', [
            'filters' => ['lembaga' => $organizationId],
            'options' => [
                'organizations' => $organizations,
                'terms' => array_map(fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys(ReportFormats::STUDENT_TERMS), ReportFormats::STUDENT_TERMS),
            ],
            'format' => $organizationId === null ? null : $formats->forOrganization($tenantId, $organizationId),
            'logo' => $organizationId === null ? null : $formats->logoDataUri($tenantId, $organizationId),
            // AC-8d: butir ekstrakurikuler & kepribadian; huruf skala untuk keterangan per huruf.
            'items' => $organizationId === null ? [] : $items->list($tenantId, $organizationId),
            'predicates' => $organizationId === null ? [] : $grading->rulesFor($tenantId, $organizationId)->predicates,
            'limits' => [
                'line' => ReportFormats::LINE_MAX,
                'title' => ReportFormats::TITLE_MAX,
                'place' => ReportFormats::PLACE_MAX,
                'role' => ReportFormats::ROLE_MAX,
                'name' => ReportFormats::NAME_MAX,
                'logoKilobytes' => ReportFormats::LOGO_MAX_KILOBYTES,
                'itemName' => ReportItems::NAME_MAX,
                'itemDescription' => ReportItems::DESCRIPTION_MAX,
            ],
        ]);
    }

    public function update(Request $request, string $organization, ReportFormats $formats): RedirectResponse
    {
        $target = Str::isUuid($organization) ? Organization::query()->find($organization) : null;

        if (! $target instanceof Organization || $target->type !== OrganizationType::Lembaga) {
            abort(404);
        }

        Gate::authorize(AcademicAccess::CLASSES_MANAGE, $target);

        $request->validate([
            'header_lines' => ['nullable', 'array', 'max:4'],
            'header_lines.*' => ['nullable', 'string', 'max:'.ReportFormats::LINE_MAX],
            'title' => ['nullable', 'string', 'max:'.ReportFormats::TITLE_MAX],
            'student_term' => ['required', 'string', 'in:'.implode(',', array_keys(ReportFormats::STUDENT_TERMS))],
            'place' => ['nullable', 'string', 'max:'.ReportFormats::PLACE_MAX],
            'show_hijri' => ['nullable', 'boolean'],
            'show_rank' => ['nullable', 'boolean'],
            'principal_title' => ['nullable', 'string', 'max:'.ReportFormats::ROLE_MAX],
            'principal_name' => ['nullable', 'string', 'max:'.ReportFormats::NAME_MAX],
            'witness_title' => ['nullable', 'string', 'max:'.ReportFormats::ROLE_MAX],
            'witness_name' => ['nullable', 'string', 'max:'.ReportFormats::NAME_MAX],
            'logo' => ['nullable', 'file', 'max:'.ReportFormats::LOGO_MAX_KILOBYTES, 'mimes:png,jpg,jpeg'],
            'remove_logo' => ['nullable', 'boolean'],
        ], [
            'logo.max' => 'Ukuran logo maksimal 1 MB.',
            'logo.mimes' => 'Logo harus berupa gambar PNG atau JPG.',
            'student_term.in' => 'Pilih sebutan peserta didik: Siswa, Santri, atau Murid.',
        ], [
            'header_lines.*' => 'baris kop',
            'title' => 'judul rapor',
            'place' => 'tempat',
            'principal_title' => 'jabatan kepala lembaga',
            'principal_name' => 'nama kepala lembaga',
            'witness_title' => 'jabatan penanda tangan',
            'witness_name' => 'nama penanda tangan',
        ]);

        $lines = $request->input('header_lines');
        $lines = is_array($lines) ? $lines : [];
        $logo = $request->file('logo');

        try {
            $changed = $formats->save($this->tenantId(), $target->id, [
                'header_lines' => array_map(fn (int $index): ?string => is_string($lines[$index] ?? null) ? $lines[$index] : null, [0, 1, 2, 3]),
                'title' => $request->string('title')->value(),
                'student_term' => $request->string('student_term')->value(),
                'place' => $request->string('place')->value(),
                'show_hijri' => $request->boolean('show_hijri'),
                'show_rank' => $request->boolean('show_rank'),
                'principal_title' => $request->string('principal_title')->value(),
                'principal_name' => $request->string('principal_name')->value(),
                'witness_title' => $request->string('witness_title')->value(),
                'witness_name' => $request->string('witness_name')->value(),
            ], $logo instanceof UploadedFile ? $logo : null, $request->boolean('remove_logo'));
        } catch (GradingException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $changed === [] ? 'Tidak ada data yang berubah.' : "Format rapor {$target->name} disimpan."]);

        return back();
    }

    // ── Ekstrakurikuler & kepribadian (PRD-002 §22.6 AC-8d) ───────────────────────

    public function storeItem(Request $request, string $organization, ReportItems $items): RedirectResponse
    {
        $target = $this->lembaga($organization);
        $request->validate($this->itemRules() + ['kind' => ['required', 'in:'.ReportItem::EXTRACURRICULAR.','.ReportItem::PERSONALITY]], [], $this->itemAttributes());

        $item = $this->attempt(fn (): ReportItem => $items->create(
            $this->tenantId(),
            $target->id,
            $request->string('kind')->value(),
            $request->string('name')->value(),
            $request->integer('sort_order'),
            (array) $request->input('descriptions', []),
        ));

        return $this->done("{$item->name} ditambahkan.");
    }

    public function updateItem(Request $request, string $item, ReportItems $items): RedirectResponse
    {
        $target = $this->item($item, $items);
        $request->validate($this->itemRules() + ['is_active' => ['nullable', 'boolean']], [], $this->itemAttributes());

        $changed = $this->attempt(fn (): array => $items->update(
            $this->tenantId(),
            $target->id,
            $request->string('name')->value(),
            $request->integer('sort_order'),
            (array) $request->input('descriptions', []),
            $request->boolean('is_active'),
        ));

        return $this->done($changed === [] ? 'Tidak ada data yang berubah.' : 'Butir disimpan.');
    }

    public function destroyItem(string $item, ReportItems $items): RedirectResponse
    {
        $target = $this->item($item, $items);
        $name = $this->attempt(fn (): string => $items->delete($this->tenantId(), $target->id));

        return $this->done("{$name} dihapus.");
    }

    public function personalityExamples(string $organization, ReportItems $items): RedirectResponse
    {
        $target = $this->lembaga($organization);
        $added = $items->addPersonalityExamples($this->tenantId(), $target->id);

        return $this->done($added === 0 ? 'Semua aspek kepribadian contoh sudah ada.' : "{$added} aspek kepribadian contoh ditambahkan.");
    }

    private function lembaga(string $organization): Organization
    {
        $target = Str::isUuid($organization) ? Organization::query()->find($organization) : null;

        if (! $target instanceof Organization || $target->type !== OrganizationType::Lembaga) {
            abort(404);
        }

        Gate::authorize(AcademicAccess::CLASSES_MANAGE, $target);

        return $target;
    }

    private function item(string $itemId, ReportItems $items): ReportItem
    {
        $item = $items->find($this->tenantId(), $itemId);

        if (! $item instanceof ReportItem) {
            abort(404);
        }

        Gate::authorize(AcademicAccess::CLASSES_MANAGE, $item);

        return $item;
    }

    /**
     * @return array<string, list<string>>
     */
    private function itemRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.ReportItems::NAME_MAX],
            'sort_order' => ['nullable', 'integer', 'between:0,'.ReportItems::ORDER_MAX],
            'descriptions' => ['nullable', 'array', 'max:'.GradingRules::MAX_LEVELS],
            'descriptions.*' => ['nullable', 'string', 'max:'.ReportItems::DESCRIPTION_MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function itemAttributes(): array
    {
        return ['name' => 'nama', 'sort_order' => 'urutan', 'descriptions.*' => 'keterangan'];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    private function attempt(callable $action): mixed
    {
        try {
            return $action();
        } catch (GradingException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    /**
     * Lembaga aktif yang kelasnya boleh dikelola pengguna di lembaga kerja ini.
     *
     * @return list<array{value: string, label: string}>
     */
    private function organizationOptions(): array
    {
        $options = [];

        foreach (Organization::query()
            ->whereIn('id', $this->authorization->visibleNodeIds(AcademicAccess::CLASSES_MANAGE))
            ->where('type', OrganizationType::Lembaga->value)
            ->where('status', OrganizationStatus::Active->value)
            ->orderBy('name')
            ->get() as $organization) {
            $options[] = ['value' => $organization->id, 'label' => $organization->name];
        }

        return $options;
    }

    private function tenantId(): string
    {
        return $this->tenantContext->require()->tenantId;
    }
}
