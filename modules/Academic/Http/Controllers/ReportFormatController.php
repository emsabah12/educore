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
use Modules\Academic\Application\Grading\ReportFormats;
use Modules\Academic\Domain\Grading\Exceptions\GradingException;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Core\Domain\Tenancy\TenantContext;

/**
 * Halaman Format rapor per lembaga (PRD-002 §22.5 AC-8c). Pilihan lembaga = lembaga aktif yang
 * boleh dikelola kelasnya (academic.classes.manage: Operator Akademik lembaga itu & Admin Yayasan).
 */
final class ReportFormatController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuthorizationService $authorization,
    ) {}

    public function index(Request $request, ReportFormats $formats): Response
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
            'limits' => [
                'line' => ReportFormats::LINE_MAX,
                'title' => ReportFormats::TITLE_MAX,
                'place' => ReportFormats::PLACE_MAX,
                'role' => ReportFormats::ROLE_MAX,
                'name' => ReportFormats::NAME_MAX,
                'logoKilobytes' => ReportFormats::LOGO_MAX_KILOBYTES,
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
