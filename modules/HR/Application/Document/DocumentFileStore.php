<?php

namespace Modules\HR\Application\Document;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\HR\Domain\Document\Exceptions\DocumentException;

/**
 * File dokumen pegawai di disk privat `local` (storage/app/private), PRD-001 §15 [OWNER 2026-10-24].
 *
 * - Path acak: `tenants/{yayasan}/hr/documents/{id dokumen}.{ext}`; nama asli hanya metadata.
 * - Jenis file ditentukan dari ISI file (finfo), bukan ekstensi kiriman pengguna.
 * - Tidak ada URL publik: file hanya keluar lewat controller setelah izin dicek.
 */
final class DocumentFileStore
{
    public const MAX_KILOBYTES = 5 * 1024;

    /** Jenis file yang diterima → ekstensi simpan. */
    public const MIME_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    public const ORIGINAL_NAME_MAX = 255;

    /**
     * Periksa file unggahan.
     *
     * @return array{mime: string, extension: string, size: int, sha256: string, name: string}
     *
     * @throws DocumentException
     */
    public function inspect(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            throw DocumentException::make('file', 'File gagal diunggah. Coba unggah ulang.');
        }

        $mime = (string) $file->getMimeType();
        $size = (int) $file->getSize();

        if (! array_key_exists($mime, self::MIME_TYPES)) {
            throw DocumentException::make('file', 'File harus berupa PDF, JPG, atau PNG.');
        }

        if ($size <= 0 || $size > self::MAX_KILOBYTES * 1024) {
            throw DocumentException::make('file', 'Ukuran file maksimal '.(self::MAX_KILOBYTES / 1024).' MB.');
        }

        $hash = hash_file('sha256', $file->getRealPath());

        if ($hash === false) {
            throw DocumentException::make('file', 'File gagal dibaca. Coba unggah ulang.');
        }

        return [
            'mime' => $mime,
            'extension' => self::MIME_TYPES[$mime],
            'size' => $size,
            'sha256' => $hash,
            'name' => self::safeName($file->getClientOriginalName(), self::MIME_TYPES[$mime]),
        ];
    }

    public static function pathFor(string $tenantId, string $documentId, string $extension): string
    {
        return "tenants/{$tenantId}/hr/documents/{$documentId}.{$extension}";
    }

    /**
     * @throws DocumentException
     */
    public function put(UploadedFile $file, string $path): void
    {
        $directory = dirname($path);
        $name = basename($path);

        if ($this->disk()->putFileAs($directory, $file, $name) === false) {
            throw DocumentException::make('file', 'File gagal disimpan di server. Coba lagi atau hubungi admin.');
        }
    }

    public function delete(string $path): void
    {
        $this->disk()->delete($path);
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    public function absolutePath(string $path): string
    {
        return $this->disk()->path($path);
    }

    /**
     * Nama file untuk tampilan & unduhan: tanpa folder, karakter kontrol, atau pemisah path; ekstensi
     * disesuaikan dengan jenis file sebenarnya.
     */
    public static function safeName(string $name, string $extension): string
    {
        $name = (string) preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', '_', basename(str_replace('\\', '/', $name)));
        $base = trim((string) preg_replace('/\.[^.]*$/', '', $name), ' ._');

        if ($base === '') {
            $base = 'dokumen';
        }

        return mb_substr($base, 0, self::ORIGINAL_NAME_MAX - 10).'.'.$extension;
    }

    private function disk(): Filesystem
    {
        return Storage::disk('local');
    }
}
