<?php

namespace Modules\HR\Application\Discipline;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Document\DocumentFileStore;
use Modules\HR\Domain\Discipline\DisciplineKind;
use Modules\HR\Domain\Discipline\Exceptions\DisciplineException;
use Modules\HR\Domain\Document\Exceptions\DocumentException;
use stdClass;
use Throwable;

/**
 * Catatan disiplin pegawai (PRD-001 §18.1, HR-7a): catat (dengan lampiran opsional), cabut, unduh lampiran.
 *
 * - Jenis Teguran lisan / tertulis / SP1–SP3; tanggal tidak di masa depan; pelanggaran 5–500 karakter; tindak lanjut
 *   maks. 500; "berlaku sampai" wajib untuk SP (bawaan 6 bulan, OD-HR-14) dan ≥ tanggal catatan.
 * - Tidak bisa diubah/dihapus: salah input → dicabut (alasan 5–300) lalu dicatat ulang.
 * - Lampiran satu file PDF/JPG/PNG maks. 2 MB di disk privat; jenis file dibaca dari isinya.
 * - Audit tanpa isi pelanggaran/alasan (data sensitif).
 *
 * Hak akses dicek pemanggil (controller).
 */
final class ManageDiscipline
{
    public const TEXT_MIN = 5;

    public const TEXT_MAX = 500;

    public const REVOKE_REASON_MAX = 300;

    public const FILE_MAX_KILOBYTES = 2 * 1024;

    /** Masa berlaku SP bawaan (OD-HR-14). */
    public const DEFAULT_VALID_MONTHS = 6;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DocumentFileStore $files,
    ) {}

    /**
     * "Berlaku sampai" bawaan: 6 bulan sejak tanggal catatan, dikurangi sehari (mis. 11 Nov → 10 Mei).
     */
    public static function defaultValidUntil(string $issuedOn): string
    {
        return CarbonImmutable::parse($issuedOn)->addMonthsNoOverflow(self::DEFAULT_VALID_MONTHS)->subDay()->toDateString();
    }

    public static function pathFor(string $tenantId, string $recordId, string $extension): string
    {
        return "tenants/{$tenantId}/hr/discipline/{$recordId}.{$extension}";
    }

    /**
     * @return string id catatan
     *
     * @throws DisciplineException
     */
    public function record(string $tenantId, string $employeeId, string $kind, string $issuedOn, string $violation, ?string $followUp, ?string $validUntil, ?UploadedFile $file, ?string $membershipId): string
    {
        $kindCase = DisciplineKind::tryFrom($kind) ?? throw DisciplineException::make('kind', 'Pilih jenis catatan disiplin.');
        $issued = $this->date($issuedOn, 'issued_on', 'tanggal');

        if ($issued > BusinessClock::todayString()) {
            throw DisciplineException::make('issued_on', 'Tanggal tidak boleh di masa depan.');
        }

        $violation = $this->text($violation, 'violation', 'Uraian pelanggaran', true) ?? '';
        $followUp = $this->text($followUp ?? '', 'follow_up', 'Tindak lanjut', false);
        $until = $validUntil === null || trim($validUntil) === '' ? null : $this->date($validUntil, 'valid_until', 'tanggal berlaku sampai');

        if ($until === null && $kindCase->isWarningLetter()) {
            throw DisciplineException::make('valid_until', 'Isi tanggal berlaku sampai untuk '.$kindCase->label().'.');
        }

        if ($until !== null && $until < $issued) {
            throw DisciplineException::make('valid_until', 'Tanggal berlaku sampai tidak boleh sebelum tanggal catatan.');
        }

        $this->employee($tenantId, $employeeId);

        $id = (string) Str::uuid7();
        $info = null;
        $path = null;

        if ($file !== null) {
            try {
                $info = $this->files->inspect($file);
            } catch (DocumentException $exception) {
                throw DisciplineException::make('file', $exception->getMessage());
            }

            if ($info['size'] > self::FILE_MAX_KILOBYTES * 1024) {
                throw DisciplineException::make('file', 'Ukuran lampiran maksimal '.(self::FILE_MAX_KILOBYTES / 1024).' MB.');
            }

            $path = self::pathFor($tenantId, $id, $info['extension']);

            try {
                $this->files->put($file, $path);
            } catch (DocumentException $exception) {
                throw DisciplineException::make('file', $exception->getMessage());
            }
        }

        try {
            DB::transaction(function () use ($tenantId, $employeeId, $id, $kindCase, $issued, $violation, $followUp, $until, $info, $path, $membershipId): void {
                DB::table('hr_discipline_records')->insert([
                    'id' => $id,
                    'tenant_id' => $tenantId,
                    'employee_id' => $employeeId,
                    'kind' => $kindCase->value,
                    'issued_on' => $issued,
                    'violation' => $violation,
                    'follow_up' => $followUp,
                    'valid_until' => $until,
                    'storage_path' => $path,
                    'original_name' => $info['name'] ?? null,
                    'mime_type' => $info['mime'] ?? null,
                    'byte_size' => $info['size'] ?? null,
                    'sha256' => $info['sha256'] ?? null,
                    'recorded_by_membership_id' => $membershipId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->audit->record(
                    HrAuditAction::DISCIPLINE_RECORDED,
                    HrAuditAction::SUBJECT_DISCIPLINE,
                    $id,
                    ['pegawai' => $employeeId, 'jenis' => $kindCase->value, 'tanggal' => $issued, 'lampiran' => $path !== null],
                    tenantId: $tenantId,
                );
            });
        } catch (Throwable $exception) {
            // Data gagal disimpan → lampiran yang sudah ditulis dihapus agar tidak ada file yatim.
            if ($path !== null) {
                $this->files->delete($path);
            }

            throw $exception;
        }

        return $id;
    }

    /**
     * @throws DisciplineException
     */
    public function revoke(string $tenantId, string $employeeId, string $recordId, string $reason, ?string $membershipId): void
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));
        $length = mb_strlen($reason);

        if ($length < self::TEXT_MIN || $length > self::REVOKE_REASON_MAX) {
            throw DisciplineException::make('revoke_reason', 'Tulis alasan pencabutan ('.self::TEXT_MIN.'–'.self::REVOKE_REASON_MAX.' karakter).');
        }

        DB::transaction(function () use ($tenantId, $employeeId, $recordId, $reason, $membershipId): void {
            $record = $this->find($tenantId, $employeeId, $recordId, lock: true);

            if ($record->revoked_at !== null) {
                throw DisciplineException::make('form', 'Catatan ini sudah dicabut.');
            }

            DB::table('hr_discipline_records')->where('id', $record->id)->update([
                'revoked_at' => now(),
                'revoke_reason' => $reason,
                'revoked_by_membership_id' => $membershipId,
                'updated_at' => now(),
            ]);

            $this->audit->record(
                HrAuditAction::DISCIPLINE_REVOKED,
                HrAuditAction::SUBJECT_DISCIPLINE,
                (string) $record->id,
                ['pegawai' => $employeeId, 'jenis' => (string) $record->kind],
                tenantId: $tenantId,
            );
        });
    }

    /**
     * Lampiran untuk diunduh: path absolut, nama unduhan, jenis file.
     *
     * @return array{path: string, name: string, mime: string}
     *
     * @throws DisciplineException catatan/lampiran tidak ada
     */
    public function attachment(string $tenantId, string $employeeId, string $recordId): array
    {
        $record = $this->find($tenantId, $employeeId, $recordId);

        if ($record->storage_path === null || ! $this->files->exists((string) $record->storage_path)) {
            throw DisciplineException::make('file', 'Lampiran tidak ditemukan.');
        }

        return [
            'path' => $this->files->absolutePath((string) $record->storage_path),
            'name' => (string) $record->original_name,
            'mime' => (string) $record->mime_type,
        ];
    }

    /**
     * @throws DisciplineException
     */
    private function find(string $tenantId, string $employeeId, string $recordId, bool $lock = false): stdClass
    {
        $record = Str::isUuid($recordId)
            ? DB::table('hr_discipline_records')
                ->where('tenant_id', $tenantId)
                ->where('employee_id', $employeeId)
                ->where('id', $recordId)
                ->when($lock, fn ($query) => $query->lockForUpdate())
                ->first()
            : null;

        if ($record === null) {
            throw DisciplineException::make('form', 'Catatan disiplin tidak ditemukan.');
        }

        return $record;
    }

    /**
     * @throws DisciplineException
     */
    private function employee(string $tenantId, string $employeeId): void
    {
        if (! Str::isUuid($employeeId) || DB::table('hr_employees')->where('tenant_id', $tenantId)->where('id', $employeeId)->doesntExist()) {
            throw DisciplineException::make('form', 'Pegawai tidak ditemukan.');
        }
    }

    /**
     * @throws DisciplineException
     */
    private function date(string $value, string $field, string $label): string
    {
        $value = trim($value);
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $value) : null;

        if ($date === null || $date->format('Y-m-d') !== $value) {
            throw DisciplineException::make($field, 'Isi '.$label.' yang benar.');
        }

        return $value;
    }

    /**
     * @throws DisciplineException
     */
    private function text(string $value, string $field, string $label, bool $required): ?string
    {
        $value = trim((string) preg_replace('/[ \t]+/u', ' ', str_replace("\r\n", "\n", $value)));

        if ($value === '') {
            if ($required) {
                throw DisciplineException::make($field, $label.' wajib diisi ('.self::TEXT_MIN.'–'.self::TEXT_MAX.' karakter).');
            }

            return null;
        }

        $length = mb_strlen($value);

        if (($required && $length < self::TEXT_MIN) || $length > self::TEXT_MAX) {
            throw DisciplineException::make($field, $label.' '.self::TEXT_MIN.'–'.self::TEXT_MAX.' karakter.');
        }

        return $value;
    }
}
