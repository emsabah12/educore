<?php

namespace Modules\HR\Application\Import;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * File impor yang sudah diunggah tetapi belum disimpan (PRD-001 §6.2).
 *
 * File disimpan di disk privat `local` (storage/app/private/hr-imports), BUKAN di folder publik.
 * Session hanya menyimpan ID-nya, dan file hanya dipakai oleh yayasan yang mengunggahnya.
 * File dihapus saat dibatalkan/diganti/disimpan, dan sisa lebih dari 24 jam disapu `hr:prune-imports`.
 */
final class PendingImportStore
{
    public const DIRECTORY = 'hr-imports';

    public const MAX_AGE_HOURS = 24;

    private const SESSION_KEY = 'hr.import.pending';

    private const NAME_MAX = 100;

    public function put(Session $session, UploadedFile $file, string $tenantId): void
    {
        $this->forget($session);

        $extension = strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
        $id = (string) Str::uuid7();

        $this->disk()->putFileAs(self::DIRECTORY, $file, "{$id}.{$extension}");

        $session->put(self::SESSION_KEY, [
            'id' => $id,
            'extension' => $extension,
            'tenant_id' => $tenantId,
            // Nama asli hanya untuk ditampilkan kembali kepada admin yang sama.
            'name' => mb_substr($file->getClientOriginalName(), 0, self::NAME_MAX),
        ]);
    }

    /**
     * File impor milik sesi ini dan yayasan ini, atau null.
     *
     * @return array{path: string, extension: 'xlsx'|'csv', name: string}|null
     */
    public function current(Session $session, string $tenantId): ?array
    {
        $pending = $session->get(self::SESSION_KEY);

        if (! is_array($pending) || ! is_string($pending['id'] ?? null) || ! Str::isUuid($pending['id'])) {
            return null;
        }

        $extension = ($pending['extension'] ?? null) === 'xlsx' ? 'xlsx' : 'csv';
        $relative = self::DIRECTORY.'/'.$pending['id'].'.'.$extension;

        if (($pending['tenant_id'] ?? null) !== $tenantId || ! $this->disk()->exists($relative)) {
            $this->forget($session);

            return null;
        }

        return [
            'path' => $this->disk()->path($relative),
            'extension' => $extension,
            'name' => is_string($pending['name'] ?? null) ? $pending['name'] : 'file impor',
        ];
    }

    public function forget(Session $session): void
    {
        $pending = $session->pull(self::SESSION_KEY);

        if (is_array($pending) && is_string($pending['id'] ?? null) && Str::isUuid($pending['id'])) {
            foreach (['xlsx', 'csv'] as $extension) {
                $this->disk()->delete(self::DIRECTORY.'/'.$pending['id'].'.'.$extension);
            }
        }
    }

    /**
     * Hapus file impor yang tertinggal lebih dari MAX_AGE_HOURS. Mengembalikan jumlah file terhapus.
     */
    public function prune(): int
    {
        $cutoff = now()->subHours(self::MAX_AGE_HOURS)->getTimestamp();
        $deleted = 0;

        foreach ($this->disk()->files(self::DIRECTORY) as $file) {
            if ($this->disk()->lastModified($file) < $cutoff && $this->disk()->delete($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function disk(): Filesystem
    {
        return Storage::disk('local');
    }
}
