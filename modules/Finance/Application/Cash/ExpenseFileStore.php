<?php

namespace Modules\Finance\Application\Cash;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Finance\Domain\Fee\Exceptions\FinanceException;

/**
 * Lampiran pengajuan & bukti realisasi pengeluaran (PRD-004 §9, KS-3) di disk privat `local`
 * (storage/app/private). Path acak `tenants/{yayasan}/finance/expenses/{acak}.{ext}`; nama asli hanya untuk
 * tampilan. Jenis file dari ISI file (finfo); tidak ada URL publik — file keluar lewat controller setelah izin dicek.
 */
final class ExpenseFileStore
{
    public const MAX_KILOBYTES = 2 * 1024;

    /** Jenis file yang diterima → ekstensi simpan. */
    public const MIME_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    /**
     * Simpan file unggahan; kembalikan [path, nama tampilan].
     *
     * @return array{path: string, name: string}
     *
     * @throws FinanceException
     */
    public function store(string $tenantId, UploadedFile $file, string $field): array
    {
        if (! $file->isValid()) {
            throw FinanceException::make($field, 'File gagal diunggah. Coba unggah ulang.');
        }

        $mime = (string) $file->getMimeType();
        $size = (int) $file->getSize();

        if (! array_key_exists($mime, self::MIME_TYPES)) {
            throw FinanceException::make($field, 'File harus berupa PDF, JPG, atau PNG.');
        }

        if ($size <= 0 || $size > self::MAX_KILOBYTES * 1024) {
            throw FinanceException::make($field, 'Ukuran file maksimal '.(self::MAX_KILOBYTES / 1024).' MB.');
        }

        $extension = self::MIME_TYPES[$mime];
        $directory = "tenants/{$tenantId}/finance/expenses";
        $name = Str::uuid7().'.'.$extension;

        if ($this->disk()->putFileAs($directory, $file, $name) === false) {
            throw FinanceException::make($field, 'File gagal disimpan di server. Coba lagi atau hubungi admin.');
        }

        return ['path' => "{$directory}/{$name}", 'name' => self::safeName($file->getClientOriginalName(), $extension)];
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
     * Nama tampilan & unduhan: tanpa folder/karakter kontrol; ekstensi mengikuti jenis file sebenarnya.
     */
    public static function safeName(string $name, string $extension): string
    {
        $name = (string) preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', '_', basename(str_replace('\\', '/', $name)));
        $base = trim((string) preg_replace('/\.[^.]*$/', '', $name), ' ._');

        return mb_substr($base === '' ? 'lampiran' : $base, 0, 200).'.'.$extension;
    }

    private function disk(): Filesystem
    {
        return Storage::disk('local');
    }
}
