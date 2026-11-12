<?php

namespace Modules\HR\Application\Training;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Document\DocumentFileStore;
use Modules\HR\Domain\Document\Exceptions\DocumentException;
use Modules\HR\Domain\Training\Exceptions\TrainingException;
use Modules\HR\Domain\Training\TrainingKind;
use Modules\HR\Domain\Training\TrainingStatus;
use stdClass;
use Throwable;

/**
 * Riwayat pelatihan & sertifikasi pegawai (PRD-001 §19.1, HR-8a; OD-HR-16).
 *
 * - Admin SDM / Admin Yayasan mencatat (langsung DITERIMA); pegawai mengajukan sendiri (MENUNGGU) lalu diverifikasi.
 * - Terima / tolak (alasan) pengajuan; batalkan (alasan) catatan yang diterima; pegawai menarik pengajuan yang
 *   masih menunggu (dihapus beserta lampirannya). Isian tidak bisa diubah.
 * - Lampiran sertifikat opsional: PDF/JPG/PNG ≤ 2 MB di disk privat; jenis file dibaca dari isinya.
 * - Audit tanpa isi catatan/alasan.
 *
 * Hak akses dicek pemanggil (controller).
 */
final class ManageTrainings
{
    public const TITLE_MIN = 3;

    public const TITLE_MAX = 150;

    public const ORGANIZER_MAX = 150;

    public const CERTIFICATE_MAX = 100;

    public const HOURS_MAX = 999;

    public const REASON_MIN = 5;

    public const REASON_MAX = 300;

    public const FILE_MAX_KILOBYTES = 2 * 1024;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DocumentFileStore $files,
    ) {}

    public static function pathFor(string $tenantId, string $trainingId, string $extension): string
    {
        return "tenants/{$tenantId}/hr/trainings/{$trainingId}.{$extension}";
    }

    /**
     * Catatan oleh Admin (langsung DITERIMA) atau pengajuan pegawai sendiri (MENUNGGU).
     *
     * @param  array<string, mixed>  $input  kind, title, organizer, start_date, end_date, hours, certificate_number, valid_until
     * @return string id catatan
     *
     * @throws TrainingException
     */
    public function record(string $tenantId, string $employeeId, array $input, ?UploadedFile $file, ?string $membershipId, bool $bySelf): string
    {
        $values = $this->values($input);

        if (! Str::isUuid($employeeId) || DB::table('hr_employees')->where('tenant_id', $tenantId)->where('id', $employeeId)->doesntExist()) {
            throw TrainingException::make('form', 'Pegawai tidak ditemukan.');
        }

        $id = (string) Str::uuid7();
        $info = null;
        $path = null;

        if ($file !== null) {
            try {
                $info = $this->files->inspect($file);
            } catch (DocumentException $exception) {
                throw TrainingException::make('file', $exception->getMessage());
            }

            if ($info['size'] > self::FILE_MAX_KILOBYTES * 1024) {
                throw TrainingException::make('file', 'Ukuran lampiran maksimal '.(self::FILE_MAX_KILOBYTES / 1024).' MB.');
            }

            $path = self::pathFor($tenantId, $id, $info['extension']);

            try {
                $this->files->put($file, $path);
            } catch (DocumentException $exception) {
                throw TrainingException::make('file', $exception->getMessage());
            }
        }

        try {
            DB::transaction(function () use ($tenantId, $employeeId, $id, $values, $info, $path, $membershipId, $bySelf): void {
                DB::table('hr_trainings')->insert($values + [
                    'id' => $id,
                    'tenant_id' => $tenantId,
                    'employee_id' => $employeeId,
                    'storage_path' => $path,
                    'original_name' => $info['name'] ?? null,
                    'mime_type' => $info['mime'] ?? null,
                    'byte_size' => $info['size'] ?? null,
                    'sha256' => $info['sha256'] ?? null,
                    'status' => $bySelf ? TrainingStatus::Pending->value : TrainingStatus::Accepted->value,
                    'submitted_by_membership_id' => $membershipId,
                    'decided_by_membership_id' => $bySelf ? null : $membershipId,
                    'decided_at' => $bySelf ? null : now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->audit->record(
                    $bySelf ? HrAuditAction::TRAINING_SUBMITTED : HrAuditAction::TRAINING_RECORDED,
                    HrAuditAction::SUBJECT_TRAINING,
                    $id,
                    ['pegawai' => $employeeId, 'jenis' => $values['kind'], 'lampiran' => $path !== null],
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
     * Terima pengajuan pegawai.
     *
     * @throws TrainingException
     */
    public function verify(string $tenantId, string $employeeId, string $trainingId, ?string $membershipId): void
    {
        $this->decide($tenantId, $employeeId, $trainingId, TrainingStatus::Pending, TrainingStatus::Accepted, null, $membershipId, HrAuditAction::TRAINING_VERIFIED);
    }

    /**
     * Tolak pengajuan pegawai (alasan tampil ke pegawai).
     *
     * @throws TrainingException
     */
    public function reject(string $tenantId, string $employeeId, string $trainingId, string $reason, ?string $membershipId): void
    {
        $this->decide($tenantId, $employeeId, $trainingId, TrainingStatus::Pending, TrainingStatus::Rejected, $this->reason($reason), $membershipId, HrAuditAction::TRAINING_REJECTED);
    }

    /**
     * Batalkan catatan yang sudah diterima (salah input).
     *
     * @throws TrainingException
     */
    public function cancel(string $tenantId, string $employeeId, string $trainingId, string $reason, ?string $membershipId): void
    {
        $this->decide($tenantId, $employeeId, $trainingId, TrainingStatus::Accepted, TrainingStatus::Cancelled, $this->reason($reason), $membershipId, HrAuditAction::TRAINING_CANCELLED);
    }

    /**
     * Pegawai menarik pengajuannya yang masih menunggu: dihapus beserta lampirannya.
     *
     * @throws TrainingException
     */
    public function withdraw(string $tenantId, string $employeeId, string $trainingId): void
    {
        $path = DB::transaction(function () use ($tenantId, $employeeId, $trainingId): ?string {
            $training = $this->find($tenantId, $employeeId, $trainingId, lock: true);

            if ((string) $training->status !== TrainingStatus::Pending->value) {
                throw TrainingException::make('form', 'Hanya pengajuan yang masih menunggu verifikasi yang bisa ditarik.');
            }

            DB::table('hr_trainings')->where('id', $training->id)->delete();

            $this->audit->record(
                HrAuditAction::TRAINING_WITHDRAWN,
                HrAuditAction::SUBJECT_TRAINING,
                (string) $training->id,
                ['pegawai' => $employeeId, 'jenis' => (string) $training->kind],
                tenantId: $tenantId,
            );

            return $training->storage_path === null ? null : (string) $training->storage_path;
        });

        if ($path !== null) {
            $this->files->delete($path);
        }
    }

    /**
     * @return array{path: string, name: string, mime: string}
     *
     * @throws TrainingException lampiran tidak ada
     */
    public function attachment(string $tenantId, string $employeeId, string $trainingId): array
    {
        $training = $this->find($tenantId, $employeeId, $trainingId);

        if ($training->storage_path === null || ! $this->files->exists((string) $training->storage_path)) {
            throw TrainingException::make('file', 'Lampiran tidak ditemukan.');
        }

        return [
            'path' => $this->files->absolutePath((string) $training->storage_path),
            'name' => (string) $training->original_name,
            'mime' => (string) $training->mime_type,
        ];
    }

    /**
     * @throws TrainingException
     */
    private function decide(string $tenantId, string $employeeId, string $trainingId, TrainingStatus $from, TrainingStatus $to, ?string $note, ?string $membershipId, string $action): void
    {
        DB::transaction(function () use ($tenantId, $employeeId, $trainingId, $from, $to, $note, $membershipId, $action): void {
            $training = $this->find($tenantId, $employeeId, $trainingId, lock: true);

            if ((string) $training->status !== $from->value) {
                throw TrainingException::make('form', $from === TrainingStatus::Pending
                    ? 'Pengajuan ini sudah diputuskan atau ditarik.'
                    : 'Hanya catatan yang sudah diterima yang bisa dibatalkan.');
            }

            DB::table('hr_trainings')->where('id', $training->id)->update([
                'status' => $to->value,
                'decision_note' => $note,
                'decided_by_membership_id' => $membershipId,
                'decided_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                $action,
                HrAuditAction::SUBJECT_TRAINING,
                (string) $training->id,
                ['pegawai' => $employeeId, 'jenis' => (string) $training->kind],
                tenantId: $tenantId,
            );
        });
    }

    /**
     * @throws TrainingException
     */
    private function find(string $tenantId, string $employeeId, string $trainingId, bool $lock = false): stdClass
    {
        $training = Str::isUuid($trainingId)
            ? DB::table('hr_trainings')
                ->where('tenant_id', $tenantId)
                ->where('employee_id', $employeeId)
                ->where('id', $trainingId)
                ->when($lock, fn ($query) => $query->lockForUpdate())
                ->first()
            : null;

        if ($training === null) {
            throw TrainingException::make('form', 'Catatan pelatihan tidak ditemukan.');
        }

        return $training;
    }

    /**
     * @throws TrainingException
     */
    private function reason(string $reason): string
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));
        $length = mb_strlen($reason);

        if ($length < self::REASON_MIN || $length > self::REASON_MAX) {
            throw TrainingException::make('reason', 'Tulis alasannya ('.self::REASON_MIN.'–'.self::REASON_MAX.' karakter).');
        }

        return $reason;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{kind: string, title: string, organizer: string|null, start_date: string, end_date: string, hours: int|null, certificate_number: string|null, valid_until: string|null}
     *
     * @throws TrainingException
     */
    private function values(array $input): array
    {
        $kind = TrainingKind::tryFrom(self::text($input['kind'] ?? '') ?? '') ?? throw TrainingException::make('kind', 'Pilih jenis kegiatan.');
        $title = self::text($input['title'] ?? '');

        if ($title === null || mb_strlen($title) < self::TITLE_MIN || mb_strlen($title) > self::TITLE_MAX) {
            throw TrainingException::make('title', 'Nama kegiatan wajib diisi ('.self::TITLE_MIN.'–'.self::TITLE_MAX.' karakter).');
        }

        $organizer = self::text($input['organizer'] ?? '');

        if ($organizer !== null && mb_strlen($organizer) > self::ORGANIZER_MAX) {
            throw TrainingException::make('organizer', 'Penyelenggara maksimal '.self::ORGANIZER_MAX.' karakter.');
        }

        $start = $this->date($input['start_date'] ?? '', 'start_date', 'tanggal mulai', true) ?? '';
        $end = $this->date($input['end_date'] ?? '', 'end_date', 'tanggal selesai', true) ?? '';

        if ($end > BusinessClock::todayString()) {
            throw TrainingException::make('end_date', 'Tanggal selesai tidak boleh di masa depan. Catat kegiatan yang sudah selesai.');
        }

        if ($end < $start) {
            throw TrainingException::make('end_date', 'Tanggal selesai tidak boleh sebelum tanggal mulai.');
        }

        $hoursText = self::text($input['hours'] ?? '');
        $hours = null;

        if ($hoursText !== null) {
            if (preg_match('/^\d{1,3}$/', $hoursText) !== 1 || (int) $hoursText < 1) {
                throw TrainingException::make('hours', 'Jumlah jam 1–'.self::HOURS_MAX.'.');
            }

            $hours = (int) $hoursText;
        }

        $certificate = self::text($input['certificate_number'] ?? '');

        if ($certificate !== null && mb_strlen($certificate) > self::CERTIFICATE_MAX) {
            throw TrainingException::make('certificate_number', 'Nomor sertifikat maksimal '.self::CERTIFICATE_MAX.' karakter.');
        }

        $validUntil = $this->date($input['valid_until'] ?? '', 'valid_until', 'tanggal berlaku sampai', false);

        if ($validUntil !== null && $validUntil < $end) {
            throw TrainingException::make('valid_until', 'Tanggal berlaku sampai tidak boleh sebelum tanggal selesai.');
        }

        return [
            'kind' => $kind->value,
            'title' => $title,
            'organizer' => $organizer,
            'start_date' => $start,
            'end_date' => $end,
            'hours' => $hours,
            'certificate_number' => $certificate,
            'valid_until' => $validUntil,
        ];
    }

    /**
     * @throws TrainingException
     */
    private function date(mixed $value, string $field, string $label, bool $required): ?string
    {
        $value = self::text($value);

        if ($value === null) {
            if ($required) {
                throw TrainingException::make($field, 'Isi '.$label.'.');
            }

            return null;
        }

        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $value) : null;

        if ($date === null || $date->format('Y-m-d') !== $value) {
            throw TrainingException::make($field, 'Isi '.$label.' yang benar.');
        }

        return $value;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $value === '' ? null : $value;
    }
}
