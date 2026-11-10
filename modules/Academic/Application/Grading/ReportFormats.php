<?php

namespace Modules\Academic\Application\Grading;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Domain\Grading\Exceptions\GradingException;
use Modules\Academic\Domain\Grading\ReportFormat;
use Modules\Core\Application\Audit\AuditLogger;

/**
 * Format rapor per lembaga (PRD-002 §22.5 AC-8c): baca (dengan bawaan) dan simpan.
 *
 * Aturan [ASUMSI disetujui owner 2026-11-11]:
 * - Kop 4 baris (masing-masing maks. 160, boleh kosong; baris 2 bawaan = nama lembaga) + logo
 *   PNG/JPG maks. 1 MB di disk privat; jenis file dari ISI file. Logo keluar sebagai data URI di
 *   halaman yang izinnya sudah dicek (rapor cetak, halaman format) — tidak ada URL publik.
 * - Judul rapor wajib (maks. 80, bawaan "LAPORAN HASIL BELAJAR"); sebutan SISWA/SANTRI/MURID.
 * - Tempat (maks. 60), tampilkan tanggal Hijriah (ya/tidak).
 * - Jabatan kepala lembaga wajib (maks. 60, bawaan "Kepala lembaga"), nama opsional (maks. 100).
 * - Penanda tangan "Mengetahui" opsional: jabatan (maks. 60) + nama (maks. 100); nama tanpa
 *   jabatan ditolak.
 * - Lembaga tanpa format → bawaan (tampilan rapor sebelum AC-8c).
 * - Tampilkan peringkat kelas (ya/tidak, AC-8d).
 *
 * Hak akses (academic.classes.manage di lembaga) dicek pemanggil.
 *
 * @phpstan-type FormatData array{header_lines: list<string|null>, title: string, student_term: string, place: string|null, show_hijri: bool, principal_title: string, principal_name: string|null, witness_title: string|null, witness_name: string|null, show_rank: bool, has_logo: bool, configured: bool}
 */
final class ReportFormats
{
    public const TITLE_DEFAULT = 'LAPORAN HASIL BELAJAR';

    public const PRINCIPAL_DEFAULT = 'Kepala lembaga';

    public const STUDENT_TERMS = ['SISWA' => 'Siswa', 'SANTRI' => 'Santri', 'MURID' => 'Murid'];

    public const LINE_MAX = 160;

    public const TITLE_MAX = 80;

    public const PLACE_MAX = 60;

    public const ROLE_MAX = 60;

    public const NAME_MAX = 100;

    public const LOGO_MAX_KILOBYTES = 1024;

    /** Jenis logo yang diterima → ekstensi simpan. */
    public const LOGO_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg'];

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Format lembaga (bawaan bila belum diatur).
     *
     * @return FormatData
     */
    public function forOrganization(string $tenantId, string $organizationId): array
    {
        $format = $this->find($tenantId, $organizationId);

        if (! $format instanceof ReportFormat) {
            return [
                'header_lines' => [null, (string) DB::table('organizations')->where('id', $organizationId)->value('name'), null, null],
                'title' => self::TITLE_DEFAULT,
                'student_term' => 'SISWA',
                'place' => null,
                'show_hijri' => false,
                'principal_title' => self::PRINCIPAL_DEFAULT,
                'principal_name' => null,
                'witness_title' => null,
                'witness_name' => null,
                'show_rank' => false,
                'has_logo' => false,
                'configured' => false,
            ];
        }

        return [
            'header_lines' => [$format->header_line1, $format->header_line2, $format->header_line3, $format->header_line4],
            'title' => $format->title,
            'student_term' => $format->student_term,
            'place' => $format->place,
            'show_hijri' => $format->show_hijri,
            'principal_title' => $format->principal_title,
            'principal_name' => $format->principal_name,
            'witness_title' => $format->witness_title,
            'witness_name' => $format->witness_name,
            'show_rank' => $format->show_rank,
            'has_logo' => $format->logo_path !== null,
            'configured' => true,
        ];
    }

    /**
     * Logo lembaga sebagai data URI (untuk halaman cetak/format yang izinnya sudah dicek), atau null.
     */
    public function logoDataUri(string $tenantId, string $organizationId): ?string
    {
        $format = $this->find($tenantId, $organizationId);

        if (! $format instanceof ReportFormat || $format->logo_path === null || $format->logo_mime === null || ! $this->disk()->exists($format->logo_path)) {
            return null;
        }

        $content = $this->disk()->get($format->logo_path);

        return $content === null ? null : "data:{$format->logo_mime};base64,".base64_encode($content);
    }

    /**
     * Simpan format lembaga; logo baru menggantikan yang lama, `$removeLogo` menghapusnya.
     *
     * @param  array{header_lines: list<string|null>, title: string|null, student_term: string, place: string|null, show_hijri: bool, principal_title: string|null, principal_name: string|null, witness_title: string|null, witness_name: string|null, show_rank?: bool}  $input
     * @return list<string> field yang berubah
     *
     * @throws GradingException
     */
    public function save(string $tenantId, string $organizationId, array $input, ?UploadedFile $logo, bool $removeLogo): array
    {
        $values = $this->validated($input);
        $stored = $logo === null ? null : $this->storeLogo($tenantId, $logo);
        $oldLogo = null;

        try {
            $changed = DB::transaction(function () use ($tenantId, $organizationId, $values, $stored, $removeLogo, &$oldLogo): array {
                $format = ReportFormat::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->where('organization_id', $organizationId)->lockForUpdate()->first()
                    ?? new ReportFormat(['tenant_id' => $tenantId, 'organization_id' => $organizationId]);

                $format->fill($values);

                if ($stored !== null || $removeLogo) {
                    $oldLogo = $format->logo_path;
                    $format->fill(['logo_path' => $stored['path'] ?? null, 'logo_mime' => $stored['mime'] ?? null]);
                }

                $changed = $format->exists ? array_keys($format->getDirty()) : ['dibuat'];

                if ($changed === []) {
                    return [];
                }

                $format->save();

                $this->audit->record(
                    AcademicAuditAction::REPORT_FORMAT_SAVED,
                    AcademicAuditAction::SUBJECT_REPORT_FORMAT,
                    $format->id,
                    ['lembaga' => (string) DB::table('organizations')->where('id', $organizationId)->value('code'), 'field_diubah' => $changed],
                    organizationId: $organizationId,
                    tenantId: $tenantId,
                );

                return $changed;
            });
        } catch (\Throwable $exception) {
            if ($stored !== null) {
                $this->disk()->delete($stored['path']);
            }

            throw $exception;
        }

        if ($oldLogo !== null && $oldLogo !== ($stored['path'] ?? null)) {
            $this->disk()->delete($oldLogo);
        }

        return $changed;
    }

    public static function termLabel(string $term): string
    {
        return self::STUDENT_TERMS[$term] ?? 'Siswa';
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * @param  array{header_lines: list<string|null>, title: string|null, student_term: string, place: string|null, show_hijri: bool, principal_title: string|null, principal_name: string|null, witness_title: string|null, witness_name: string|null, show_rank?: bool}  $input
     * @return array<string, string|bool|null>
     *
     * @throws GradingException
     */
    private function validated(array $input): array
    {
        $values = [];

        foreach ([0, 1, 2, 3] as $index) {
            $line = self::clean($input['header_lines'][$index] ?? null);
            $number = $index + 1;

            if ($line !== null && mb_strlen($line) > self::LINE_MAX) {
                throw GradingException::make("header_lines.{$index}", "Baris kop {$number} maksimal ".self::LINE_MAX.' karakter.');
            }

            $values["header_line{$number}"] = $line;
        }

        $title = self::clean($input['title']) ?? self::TITLE_DEFAULT;
        $principalTitle = self::clean($input['principal_title']) ?? self::PRINCIPAL_DEFAULT;
        $witnessTitle = self::clean($input['witness_title']);
        $witnessName = self::clean($input['witness_name']);

        $limits = [
            'title' => [$title, self::TITLE_MAX, 'Judul rapor'],
            'place' => [self::clean($input['place']), self::PLACE_MAX, 'Tempat'],
            'principal_title' => [$principalTitle, self::ROLE_MAX, 'Jabatan kepala lembaga'],
            'principal_name' => [self::clean($input['principal_name']), self::NAME_MAX, 'Nama kepala lembaga'],
            'witness_title' => [$witnessTitle, self::ROLE_MAX, 'Jabatan penanda tangan "Mengetahui"'],
            'witness_name' => [$witnessName, self::NAME_MAX, 'Nama penanda tangan "Mengetahui"'],
        ];

        foreach ($limits as $field => [$value, $max, $label]) {
            if ($value !== null && mb_strlen($value) > $max) {
                throw GradingException::make($field, "{$label} maksimal {$max} karakter.");
            }

            $values[$field] = $value;
        }

        if ($witnessName !== null && $witnessTitle === null) {
            throw GradingException::make('witness_title', 'Isi jabatan penanda tangan "Mengetahui" (mis. Khodimul Ma\'had).');
        }

        if (! array_key_exists($input['student_term'], self::STUDENT_TERMS)) {
            throw GradingException::make('student_term', 'Pilih sebutan peserta didik: Siswa, Santri, atau Murid.');
        }

        $values['student_term'] = $input['student_term'];
        $values['show_hijri'] = $input['show_hijri'];
        $values['show_rank'] = $input['show_rank'] ?? false;

        return $values;
    }

    /**
     * @return array{path: string, mime: string}
     *
     * @throws GradingException
     */
    private function storeLogo(string $tenantId, UploadedFile $file): array
    {
        if (! $file->isValid()) {
            throw GradingException::make('logo', 'Logo gagal diunggah. Coba unggah ulang.');
        }

        // Jenis file dari ISI file (bukan nama/header kiriman browser).
        $path = $file->getRealPath();
        $mime = $path === false ? '' : (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $size = (int) $file->getSize();

        if (! array_key_exists($mime, self::LOGO_TYPES)) {
            throw GradingException::make('logo', 'Logo harus berupa gambar PNG atau JPG.');
        }

        if ($size <= 0 || $size > self::LOGO_MAX_KILOBYTES * 1024) {
            throw GradingException::make('logo', 'Ukuran logo maksimal 1 MB.');
        }

        $directory = "tenants/{$tenantId}/academic/report-logos";
        $name = Str::uuid7().'.'.self::LOGO_TYPES[$mime];

        if ($this->disk()->putFileAs($directory, $file, $name) === false) {
            throw GradingException::make('logo', 'Logo gagal disimpan di server. Coba lagi atau hubungi admin.');
        }

        return ['path' => "{$directory}/{$name}", 'mime' => $mime];
    }

    private function find(string $tenantId, string $organizationId): ?ReportFormat
    {
        return ReportFormat::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->where('organization_id', $organizationId)->first();
    }

    /** Spasi dirapikan; kosong → null. */
    private static function clean(mixed $value): ?string
    {
        $text = is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', $value)) : '';

        return $text === '' ? null : $text;
    }

    private function disk(): Filesystem
    {
        return Storage::disk('local');
    }
}
