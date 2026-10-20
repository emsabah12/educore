<?php

namespace Modules\Core\Support\Import;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * File impor yang sudah diunggah tetapi belum disimpan, untuk modul mana pun ($directory dan
 * $sessionKey milik modul itu; pola sama dengan impor pegawai PRD-001 §6.2).
 *
 * File disimpan di disk privat `local` (storage/app/private/{directory}), BUKAN di folder publik.
 * Session hanya menyimpan ID-nya, dan file hanya dipakai oleh yayasan yang mengunggahnya.
 * $meta = keterangan tambahan modul (mis. lembaga tujuan), ikut disimpan di session.
 */
final class PendingImportFiles
{
    public const MAX_AGE_HOURS = 24;

    private const NAME_MAX = 100;

    /**
     * @param  array<string, string>  $meta
     */
    public function put(Session $session, string $directory, string $sessionKey, UploadedFile $file, string $tenantId, array $meta = []): void
    {
        $this->forget($session, $directory, $sessionKey);

        $extension = strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
        $id = (string) Str::uuid7();

        $this->disk()->putFileAs($directory, $file, "{$id}.{$extension}");

        $session->put($sessionKey, [
            'id' => $id,
            'extension' => $extension,
            'tenant_id' => $tenantId,
            // Nama asli hanya untuk ditampilkan kembali kepada pengguna yang sama.
            'name' => mb_substr($file->getClientOriginalName(), 0, self::NAME_MAX),
            'meta' => $meta,
        ]);
    }

    /**
     * File impor milik sesi ini dan yayasan ini, atau null.
     *
     * @return array{path: string, extension: 'xlsx'|'csv', name: string, meta: array<string, string>}|null
     */
    public function current(Session $session, string $directory, string $sessionKey, string $tenantId): ?array
    {
        $pending = $session->get($sessionKey);

        if (! is_array($pending) || ! is_string($pending['id'] ?? null) || ! Str::isUuid($pending['id'])) {
            return null;
        }

        $extension = ($pending['extension'] ?? null) === 'xlsx' ? 'xlsx' : 'csv';
        $relative = $directory.'/'.$pending['id'].'.'.$extension;

        if (($pending['tenant_id'] ?? null) !== $tenantId || ! $this->disk()->exists($relative)) {
            $this->forget($session, $directory, $sessionKey);

            return null;
        }

        $meta = [];

        foreach ((array) ($pending['meta'] ?? []) as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $meta[$key] = $value;
            }
        }

        return [
            'path' => $this->disk()->path($relative),
            'extension' => $extension,
            'name' => is_string($pending['name'] ?? null) ? $pending['name'] : 'file impor',
            'meta' => $meta,
        ];
    }

    public function forget(Session $session, string $directory, string $sessionKey): void
    {
        $pending = $session->pull($sessionKey);

        if (is_array($pending) && is_string($pending['id'] ?? null) && Str::isUuid($pending['id'])) {
            foreach (['xlsx', 'csv'] as $extension) {
                $this->disk()->delete($directory.'/'.$pending['id'].'.'.$extension);
            }
        }
    }

    /**
     * Hapus file impor yang tertinggal lebih dari MAX_AGE_HOURS. Mengembalikan jumlah file terhapus.
     */
    public function prune(string $directory): int
    {
        $cutoff = now()->subHours(self::MAX_AGE_HOURS)->getTimestamp();
        $deleted = 0;

        foreach ($this->disk()->files($directory) as $file) {
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
