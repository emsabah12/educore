<?php

namespace Modules\Dormitory\Application\Import;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\UploadedFile;
use Modules\Core\Support\Import\PendingImportFiles;

/**
 * File impor penempatan yang sudah diunggah tetapi belum disimpan (PRD-008 §20), beserta asrama tujuannya.
 * Disimpan di storage/app/private/dormitory-imports; sisa lebih dari 24 jam disapu `dormitory:prune-imports`.
 */
final class PendingPlacementImport
{
    public const DIRECTORY = 'dormitory-imports';

    private const SESSION_KEY = 'dormitory.import.pending';

    public function __construct(
        private readonly PendingImportFiles $files,
    ) {}

    public function put(Session $session, UploadedFile $file, string $tenantId, string $dormitoryId): void
    {
        $this->files->put($session, self::DIRECTORY, self::SESSION_KEY, $file, $tenantId, ['dormitory_id' => $dormitoryId]);
    }

    /**
     * @return array{path: string, extension: 'xlsx'|'csv', name: string, dormitory_id: string}|null
     */
    public function current(Session $session, string $tenantId): ?array
    {
        $pending = $this->files->current($session, self::DIRECTORY, self::SESSION_KEY, $tenantId);
        $dormitoryId = $pending['meta']['dormitory_id'] ?? null;

        if ($pending === null || $dormitoryId === null) {
            return null;
        }

        return [
            'path' => $pending['path'],
            'extension' => $pending['extension'],
            'name' => $pending['name'],
            'dormitory_id' => $dormitoryId,
        ];
    }

    public function forget(Session $session): void
    {
        $this->files->forget($session, self::DIRECTORY, self::SESSION_KEY);
    }

    public function prune(): int
    {
        return $this->files->prune(self::DIRECTORY);
    }
}
