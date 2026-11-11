<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Discipline\DisciplineRecords;
use Modules\HR\Application\Discipline\ManageDiscipline;
use Modules\HR\Application\Employee\EmployeeVisibility;
use Modules\HR\Domain\Discipline\DisciplineKind;
use Modules\HR\Domain\Discipline\Exceptions\DisciplineException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Catatan disiplin pegawai (PRD-001 §18.1, HR-7a; OD-HR-13).
 *
 * - Daftar & unduh lampiran: `hr.discipline.view` sesuai cakupan lihat pegawai (Kepala Lembaga → pegawai
 *   lembaganya). Tanpa izin 403; pegawai di luar cakupan 404.
 * - Catat & cabut: `hr.discipline.manage` tingkat Yayasan (Admin SDM / Admin Yayasan); selain itu 403.
 */
final class DisciplineController
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, DisciplineRecords $records, EmployeeVisibility $visibility): Response
    {
        Gate::authorize(HrAccess::DISCIPLINE_VIEW);

        $organizations = $this->organizationOptions($visibility);
        $organization = $request->string('lembaga')->value();
        $organization = in_array($organization, array_column($organizations, 'value'), true) ? $organization : null;
        $kind = DisciplineKind::tryFrom($request->string('jenis')->value())?->value;
        $status = $request->string('status')->value() === DisciplineRecords::FILTER_ALL ? DisciplineRecords::FILTER_ALL : DisciplineRecords::FILTER_ACTIVE;

        $page = $records->list(
            $this->tenantContext->require()->tenantId,
            fn (Builder $query) => $visibility->apply($query, HrAccess::DISCIPLINE_VIEW),
            $organization,
            $kind,
            $status,
            $request->integer('page', 1),
        );

        return Inertia::render('hr/discipline/index', [
            'records' => $page->withQueryString(),
            'filters' => ['lembaga' => $organization, 'jenis' => $kind, 'status' => $status],
            'options' => [
                'organizations' => $organizations,
                'kinds' => self::kindOptions(),
            ],
        ]);
    }

    public function store(Request $request, string $employee, ManageDiscipline $discipline): RedirectResponse
    {
        $this->authorizeManage();

        $request->validate([
            'file' => ['nullable', 'file', 'max:'.ManageDiscipline::FILE_MAX_KILOBYTES, 'mimes:pdf,jpg,jpeg,png'],
        ], [
            'file.mimes' => 'Lampiran harus berupa PDF, JPG, atau PNG.',
            'file.max' => 'Ukuran lampiran maksimal '.(ManageDiscipline::FILE_MAX_KILOBYTES / 1024).' MB.',
            'file.uploaded' => 'Lampiran gagal diunggah. Pastikan ukurannya maksimal '.(ManageDiscipline::FILE_MAX_KILOBYTES / 1024).' MB.',
        ]);

        $file = $request->file('file');
        $context = $this->tenantContext->require();

        $this->attempt(fn () => $discipline->record(
            $context->tenantId,
            $employee,
            $request->string('kind')->value(),
            $request->string('issued_on')->value(),
            $request->string('violation')->value(),
            $request->filled('follow_up') ? $request->string('follow_up')->value() : null,
            $request->filled('valid_until') ? $request->string('valid_until')->value() : null,
            $file instanceof UploadedFile ? $file : null,
            $context->membershipId,
        ));

        return $this->done('Catatan disiplin disimpan.');
    }

    public function revoke(Request $request, string $employee, string $record, ManageDiscipline $discipline): RedirectResponse
    {
        $this->authorizeManage();
        $context = $this->tenantContext->require();

        $this->attempt(fn () => $discipline->revoke($context->tenantId, $employee, $record, $request->string('revoke_reason')->value(), $context->membershipId));

        return $this->done('Catatan disiplin dicabut. Catatannya tetap tersimpan sebagai riwayat.');
    }

    public function download(string $employee, string $record, EmployeeVisibility $visibility, ManageDiscipline $discipline): BinaryFileResponse
    {
        if (! $visibility->canSee($employee, HrAccess::DISCIPLINE_VIEW)) {
            abort($visibility->canSee($employee, HrAccess::EMPLOYEES_VIEW) ? 403 : 404);
        }

        try {
            $file = $discipline->attachment($this->tenantContext->require()->tenantId, $employee, $record);
        } catch (DisciplineException) {
            abort(404);
        }

        return self::send($file);
    }

    /**
     * @param  array{path: string, name: string, mime: string}  $file
     */
    public static function send(array $file): BinaryFileResponse
    {
        return response()->download($file['path'], $file['name'], [
            'Content-Type' => $file['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function kindOptions(): array
    {
        return array_map(fn (DisciplineKind $kind): array => ['value' => $kind->value, 'label' => $kind->label()], DisciplineKind::cases());
    }

    private function authorizeManage(): void
    {
        $this->authorization->inspectTarget(HrAccess::DISCIPLINE_MANAGE, null, null)->authorize();
    }

    /**
     * Lembaga aktif yang boleh dilihat (semua bila tingkat Yayasan).
     *
     * @return list<array{value: string, label: string}>
     */
    private function organizationOptions(EmployeeVisibility $visibility): array
    {
        $query = Organization::query()->where('status', OrganizationStatus::Active->value)->orderBy('code');

        if (! $visibility->seesWholeTenant(HrAccess::DISCIPLINE_VIEW)) {
            $query->whereIn('id', $this->authorization->visibleNodeIds(HrAccess::DISCIPLINE_VIEW));
        }

        $options = [];

        foreach ($query->get() as $organization) {
            $options[] = ['value' => (string) $organization->id, 'label' => (string) $organization->name];
        }

        return $options;
    }

    /**
     * @param  callable(): mixed  $action
     */
    private function attempt(callable $action): void
    {
        try {
            $action();
        } catch (DisciplineException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
