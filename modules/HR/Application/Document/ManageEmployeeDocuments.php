<?php

namespace Modules\HR\Application\Document;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Domain\Catalog\DocumentType;
use Modules\HR\Domain\Document\EmployeeDocument;
use Modules\HR\Domain\Document\Exceptions\DocumentException;
use Modules\HR\Domain\Employee\Employee;
use Throwable;

/**
 * Dokumen & SK pegawai (PRD-001 §15, HR-2a): tambah (dengan file), ubah isian, batalkan, unduh.
 *
 * Aturan [ASUMSI disetujui 2026-10-24]:
 * - jenis wajib dan aktif; nomor opsional (maks. 100); judul wajib (maks. 150); catatan maks. 500;
 * - tanggal terbit tidak di masa depan; "berlaku sampai" ≥ "berlaku mulai"; jenis yang punya masa
 *   berlaku → "berlaku sampai" wajib;
 * - satu file PDF/JPG/PNG maks. 5 MB; file tidak bisa diganti — salah file → batalkan + unggah baru;
 * - dokumen dibatalkan tidak bisa diubah, tetap tersimpan beserta file-nya;
 * - audit hanya mencatat jenis & nama field yang berubah, bukan isinya (PRD-001 §4.5).
 *
 * Hak akses dicek pemanggil (controller).
 */
final class ManageEmployeeDocuments
{
    public const NUMBER_MAX = 100;

    public const TITLE_MAX = 150;

    public const NOTE_MAX = 500;

    public const VOID_REASON_MAX = 300;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DocumentFileStore $files,
    ) {}

    /**
     * @throws DocumentException
     */
    public function add(string $tenantId, string $employeeId, DocumentData $data, UploadedFile $file, ?string $membershipId): EmployeeDocument
    {
        $this->employee($tenantId, $employeeId);
        [$type, $values] = $this->check($tenantId, $data, null);
        $info = $this->files->inspect($file);

        $documentId = (string) Str::uuid7();
        $path = DocumentFileStore::pathFor($tenantId, $documentId, $info['extension']);

        // File ditulis dulu; bila penyimpanan data gagal, file dihapus lagi agar tidak ada file yatim.
        $this->files->put($file, $path);

        try {
            return DB::transaction(function () use ($tenantId, $employeeId, $documentId, $path, $info, $type, $values, $membershipId): EmployeeDocument {
                $document = new EmployeeDocument;
                $document->id = $documentId;
                $document->fill([
                    'tenant_id' => $tenantId,
                    'employee_id' => $employeeId,
                    ...$values,
                    'storage_path' => $path,
                    'original_name' => $info['name'],
                    'mime_type' => $info['mime'],
                    'byte_size' => $info['size'],
                    'sha256' => $info['sha256'],
                    'uploaded_by_membership_id' => $membershipId,
                ]);
                $document->save();

                $this->audit->record(
                    HrAuditAction::DOCUMENT_ADDED,
                    HrAuditAction::SUBJECT_DOCUMENT,
                    $document->id,
                    ['pegawai' => $employeeId, 'jenis' => $type->code, 'ukuran' => $info['size'], 'sha256' => $info['sha256']],
                    tenantId: $tenantId,
                );

                return $document;
            });
        } catch (Throwable $exception) {
            $this->files->delete($path);

            throw $exception;
        }
    }

    /**
     * @return list<string> nama field yang berubah (kosong = tidak ada perubahan)
     *
     * @throws DocumentException
     */
    public function update(string $tenantId, string $employeeId, string $documentId, DocumentData $data): array
    {
        return DB::transaction(function () use ($tenantId, $employeeId, $documentId, $data): array {
            $document = $this->lock($tenantId, $employeeId, $documentId);

            if ($document->isVoided()) {
                throw DocumentException::make('form', 'Dokumen yang sudah dibatalkan tidak bisa diubah.');
            }

            [$type, $values] = $this->check($tenantId, $data, $document->document_type_id);
            $before = $this->values($document);
            $changed = [];

            foreach ($values as $field => $value) {
                if ($before[$field] !== $value) {
                    $changed[] = $field;
                }
            }

            if ($changed === []) {
                return [];
            }

            $document->fill($values);
            $document->save();

            $this->audit->record(
                HrAuditAction::DOCUMENT_UPDATED,
                HrAuditAction::SUBJECT_DOCUMENT,
                $document->id,
                ['pegawai' => $employeeId, 'jenis' => $type->code, 'field' => $changed],
                tenantId: $tenantId,
            );

            return $changed;
        });
    }

    /**
     * @throws DocumentException
     */
    public function void(string $tenantId, string $employeeId, string $documentId, string $reason, ?string $membershipId): EmployeeDocument
    {
        $reason = $this->clean($reason);

        if ($reason === null || mb_strlen($reason) > self::VOID_REASON_MAX) {
            throw DocumentException::make('void_reason', 'Tulis alasan pembatalan, maksimal '.self::VOID_REASON_MAX.' karakter.');
        }

        return DB::transaction(function () use ($tenantId, $employeeId, $documentId, $reason, $membershipId): EmployeeDocument {
            $document = $this->lock($tenantId, $employeeId, $documentId);

            if ($document->isVoided()) {
                throw DocumentException::make('form', 'Dokumen ini sudah dibatalkan.');
            }

            $document->voided_at = now()->toImmutable();
            $document->void_reason = $reason;
            $document->voided_by_membership_id = $membershipId;
            $document->save();

            $this->audit->record(
                HrAuditAction::DOCUMENT_VOIDED,
                HrAuditAction::SUBJECT_DOCUMENT,
                $document->id,
                ['pegawai' => $employeeId, 'jenis' => $this->typeCode($document)],
                tenantId: $tenantId,
            );

            return $document;
        });
    }

    /**
     * Siapkan unduhan dan catat di audit (dokumen pegawai termasuk data sensitif).
     *
     * @return array{path: string, name: string, mime: string}
     *
     * @throws DocumentException
     */
    public function download(string $tenantId, string $employeeId, string $documentId): array
    {
        $document = $this->find($tenantId, $employeeId, $documentId);

        if (! $this->files->exists($document->storage_path)) {
            throw DocumentException::make('form', 'File dokumen tidak ditemukan di server. Hubungi Admin SDM untuk mengunggah ulang.');
        }

        $this->audit->record(
            HrAuditAction::DOCUMENT_DOWNLOADED,
            HrAuditAction::SUBJECT_DOCUMENT,
            $document->id,
            ['pegawai' => $employeeId, 'jenis' => $this->typeCode($document)],
            tenantId: $tenantId,
        );

        return [
            'path' => $this->files->absolutePath($document->storage_path),
            'name' => $document->original_name,
            'mime' => $document->mime_type,
        ];
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * @return array{0: DocumentType, 1: array{document_type_id: string, number: string|null, title: string, issued_on: string|null, valid_from: string|null, valid_until: string|null, note: string|null}}
     *
     * @throws DocumentException
     */
    private function check(string $tenantId, DocumentData $data, ?string $currentTypeId): array
    {
        $type = Str::isUuid($data->documentTypeId)
            ? DocumentType::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($data->documentTypeId)->first()
            : null;

        if (! $type instanceof DocumentType) {
            throw DocumentException::make('document_type_id', 'Pilih jenis dokumen.');
        }

        // Jenis yang sudah dinonaktifkan tetap boleh dipertahankan oleh dokumen lama.
        if (! $type->is_active && $type->id !== $currentTypeId) {
            throw DocumentException::make('document_type_id', "Jenis dokumen {$type->name} sudah nonaktif.");
        }

        $number = $this->clean($data->number);
        $title = $this->clean($data->title);
        $note = $this->clean($data->note);

        if ($number !== null && mb_strlen($number) > self::NUMBER_MAX) {
            throw DocumentException::make('number', 'Nomor dokumen maksimal '.self::NUMBER_MAX.' karakter.');
        }

        if ($title === null || mb_strlen($title) > self::TITLE_MAX) {
            throw DocumentException::make('title', 'Judul dokumen wajib diisi, maksimal '.self::TITLE_MAX.' karakter.');
        }

        if ($note !== null && mb_strlen($note) > self::NOTE_MAX) {
            throw DocumentException::make('note', 'Catatan maksimal '.self::NOTE_MAX.' karakter.');
        }

        $issuedOn = $this->date($data->issuedOn, 'issued_on', 'Tanggal terbit');
        $validFrom = $this->date($data->validFrom, 'valid_from', 'Tanggal berlaku mulai');
        $validUntil = $this->date($data->validUntil, 'valid_until', 'Tanggal berlaku sampai');

        if ($issuedOn !== null && $issuedOn > BusinessClock::todayString()) {
            throw DocumentException::make('issued_on', 'Tanggal terbit tidak boleh di masa depan.');
        }

        if ($type->has_expiry && $validUntil === null) {
            throw DocumentException::make('valid_until', "{$type->name} punya masa berlaku: isi tanggal berlaku sampai.");
        }

        if ($validFrom !== null && $validUntil !== null && $validUntil < $validFrom) {
            throw DocumentException::make('valid_until', 'Tanggal berlaku sampai tidak boleh sebelum tanggal berlaku mulai.');
        }

        return [$type, [
            'document_type_id' => $type->id,
            'number' => $number,
            'title' => $title,
            'issued_on' => $issuedOn,
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'note' => $note,
        ]];
    }

    /**
     * @return array{document_type_id: string, number: string|null, title: string, issued_on: string|null, valid_from: string|null, valid_until: string|null, note: string|null}
     */
    private function values(EmployeeDocument $document): array
    {
        return [
            'document_type_id' => $document->document_type_id,
            'number' => $document->number,
            'title' => $document->title,
            'issued_on' => $document->issued_on?->toDateString(),
            'valid_from' => $document->valid_from?->toDateString(),
            'valid_until' => $document->valid_until?->toDateString(),
            'note' => $document->note,
        ];
    }

    /**
     * @throws DocumentException
     */
    private function date(?string $value, string $field, string $label): ?string
    {
        $value = $this->clean($value);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw DocumentException::make($field, "{$label} tidak valid.");
        }

        return $value;
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) preg_replace('/[ \t]+/u', ' ', (string) $value));

        return $value === '' ? null : $value;
    }

    /**
     * @throws DocumentException
     */
    private function employee(string $tenantId, string $employeeId): Employee
    {
        $employee = Str::isUuid($employeeId)
            ? Employee::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($employeeId)->first()
            : null;

        if (! $employee instanceof Employee) {
            throw DocumentException::make('form', 'Pegawai tidak ditemukan di yayasan ini.');
        }

        return $employee;
    }

    /**
     * @throws DocumentException
     */
    private function find(string $tenantId, string $employeeId, string $documentId): EmployeeDocument
    {
        $document = Str::isUuid($documentId) && Str::isUuid($employeeId)
            ? EmployeeDocument::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->where('employee_id', $employeeId)->whereKey($documentId)->first()
            : null;

        if (! $document instanceof EmployeeDocument) {
            throw DocumentException::make('form', 'Dokumen tidak ditemukan.');
        }

        return $document;
    }

    /**
     * @throws DocumentException
     */
    private function lock(string $tenantId, string $employeeId, string $documentId): EmployeeDocument
    {
        $document = Str::isUuid($documentId) && Str::isUuid($employeeId)
            ? EmployeeDocument::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->where('employee_id', $employeeId)->whereKey($documentId)->lockForUpdate()->first()
            : null;

        if (! $document instanceof EmployeeDocument) {
            throw DocumentException::make('form', 'Dokumen tidak ditemukan.');
        }

        return $document;
    }

    private function typeCode(EmployeeDocument $document): string
    {
        return (string) DocumentType::query()->withoutGlobalScope('tenant')->whereKey($document->document_type_id)->value('code');
    }
}
