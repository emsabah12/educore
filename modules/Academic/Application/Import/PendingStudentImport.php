<?php

namespace Modules\Academic\Application\Import;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\UploadedFile;
use Modules\Core\Support\Import\PendingImportFiles;

/**
 * File impor siswa yang sudah diunggah tetapi belum disimpan (PRD-002 AC-1e), beserta lembaga
 * tujuannya. Disimpan di storage/app/private/academic-imports; sisa lebih dari 24 jam disapu
 * `academic:prune-imports`.
 */
final class PendingStudentImport
{
    public const DIRECTORY = 'academic-imports';

    private const SESSION_KEY = 'academic.import.pending';

    public function __construct(
        private readonly PendingImportFiles $files,
    ) {}

    public function put(Session $session, UploadedFile $file, string $tenantId, string $organizationId): void
    {
        $this->files->put($session, self::DIRECTORY, self::SESSION_KEY, $file, $tenantId, ['organization_id' => $organizationId]);
    }

    /**
     * @return array{path: string, extension: 'xlsx'|'csv', name: string, organization_id: string}|null
     */
    public function current(Session $session, string $tenantId): ?array
    {
        $pending = $this->files->current($session, self::DIRECTORY, self::SESSION_KEY, $tenantId);

        if ($pending === null) {
            return null;
        }

        $organizationId = $pending['meta']['organization_id'] ?? null;

        if ($organizationId === null) {
            $this->forget($session);

            return null;
        }

        return [
            'path' => $pending['path'],
            'extension' => $pending['extension'],
            'name' => $pending['name'],
            'organization_id' => $organizationId,
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
