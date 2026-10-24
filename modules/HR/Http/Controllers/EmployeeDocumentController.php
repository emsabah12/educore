<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Document\DocumentData;
use Modules\HR\Application\Document\DocumentFileStore;
use Modules\HR\Application\Document\ManageEmployeeDocuments;
use Modules\HR\Application\Employee\EmployeeVisibility;
use Modules\HR\Domain\Document\Exceptions\DocumentException;
use Modules\HR\Domain\Employee\Employee;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Dokumen & SK di detail pegawai (PRD-001 §15, HR-2a).
 *
 * - Tambah, ubah isian, batalkan: hanya Admin SDM / Admin Yayasan di tingkat Yayasan (OD-HR-02),
 *   yaitu `hr.employees.manage` tenant-wide. Tanpa izin → 403; pegawai yayasan lain → 404.
 * - Unduh: yang boleh melihat data pribadi pegawai ini (`hr.employees.view_personal` sesuai cakupan
 *   lembaga). Melihat pegawai tetapi tanpa data pribadi → 403; di luar cakupan → 404.
 */
final class EmployeeDocumentController
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function store(Request $request, string $employee, ManageEmployeeDocuments $documents): RedirectResponse
    {
        $target = $this->authorizeManage($employee);

        $request->validate([
            ...$this->rules(),
            'file' => ['required', 'file', 'max:'.DocumentFileStore::MAX_KILOBYTES, 'mimes:pdf,jpg,jpeg,png'],
        ], $this->messages(), $this->attributes());

        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => 'Pilih file dokumen.']);
        }

        $context = $this->tenantContext->require();

        $document = $this->attempt(fn () => $documents->add($context->tenantId, $target->id, $this->data($request), $file, $context->membershipId));

        return $this->done("Dokumen \"{$document->title}\" ditambahkan.");
    }

    public function update(Request $request, string $employee, string $document, ManageEmployeeDocuments $documents): RedirectResponse
    {
        $target = $this->authorizeManage($employee);

        $request->validate($this->rules(), $this->messages(), $this->attributes());

        $changed = $this->attempt(fn () => $documents->update($this->tenantContext->require()->tenantId, $target->id, $document, $this->data($request)));

        return $this->done($changed === [] ? 'Tidak ada perubahan.' : 'Isian dokumen disimpan.');
    }

    public function void(Request $request, string $employee, string $document, ManageEmployeeDocuments $documents): RedirectResponse
    {
        $target = $this->authorizeManage($employee);

        $request->validate([
            'void_reason' => ['required', 'string', 'max:'.ManageEmployeeDocuments::VOID_REASON_MAX],
        ], [], ['void_reason' => 'alasan pembatalan']);

        $context = $this->tenantContext->require();
        $voided = $this->attempt(fn () => $documents->void($context->tenantId, $target->id, $document, $request->string('void_reason')->value(), $context->membershipId));

        return $this->done("Dokumen \"{$voided->title}\" dibatalkan. File-nya tetap tersimpan sebagai riwayat.");
    }

    public function download(string $employee, string $document, EmployeeVisibility $visibility, ManageEmployeeDocuments $documents): BinaryFileResponse
    {
        if (! $visibility->canSee($employee, HrAccess::EMPLOYEES_VIEW_PERSONAL)) {
            // Boleh melihat pegawai tetapi tidak data pribadinya → 403; selain itu jangan bocorkan keberadaannya.
            abort($visibility->canSee($employee, HrAccess::EMPLOYEES_VIEW) ? 403 : 404);
        }

        try {
            $file = $documents->download($this->tenantContext->require()->tenantId, $employee, $document);
        } catch (DocumentException) {
            abort(404);
        }

        return response()->download($file['path'], $file['name'], [
            'Content-Type' => $file['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function authorizeManage(string $employeeId): Employee
    {
        $this->authorization->inspectTarget(HrAccess::EMPLOYEES_MANAGE, null, null)->authorize();

        $employee = Str::isUuid($employeeId) ? Employee::query()->find($employeeId) : null;

        if (! $employee instanceof Employee) {
            abort(404);
        }

        return $employee;
    }

    /**
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        return [
            'document_type_id' => ['required', 'uuid'],
            'number' => ['nullable', 'string', 'max:'.ManageEmployeeDocuments::NUMBER_MAX],
            'title' => ['required', 'string', 'max:'.ManageEmployeeDocuments::TITLE_MAX],
            'issued_on' => ['nullable', 'date_format:Y-m-d'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:'.ManageEmployeeDocuments::NOTE_MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'document_type_id.required' => 'Pilih jenis dokumen.',
            'document_type_id.uuid' => 'Pilih jenis dokumen.',
            'file.required' => 'Pilih file dokumen.',
            'file.mimes' => 'File harus berupa PDF, JPG, atau PNG.',
            'file.max' => 'Ukuran file maksimal '.(DocumentFileStore::MAX_KILOBYTES / 1024).' MB.',
            'file.uploaded' => 'File gagal diunggah. Pastikan ukurannya maksimal '.(DocumentFileStore::MAX_KILOBYTES / 1024).' MB.',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function attributes(): array
    {
        return [
            'number' => 'nomor dokumen',
            'title' => 'judul',
            'issued_on' => 'tanggal terbit',
            'valid_from' => 'tanggal berlaku mulai',
            'valid_until' => 'tanggal berlaku sampai',
            'note' => 'catatan',
            'file' => 'file',
        ];
    }

    private function data(Request $request): DocumentData
    {
        $optional = fn (string $key): ?string => $request->filled($key) ? $request->string($key)->value() : null;

        return new DocumentData(
            documentTypeId: $request->string('document_type_id')->value(),
            number: $optional('number'),
            title: $request->string('title')->value(),
            issuedOn: $optional('issued_on'),
            validFrom: $optional('valid_from'),
            validUntil: $optional('valid_until'),
            note: $optional('note'),
        );
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
        } catch (DocumentException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
