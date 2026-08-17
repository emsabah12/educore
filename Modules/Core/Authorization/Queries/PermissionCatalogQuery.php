<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Queries;

use Modules\Core\Authorization\Models\Permission;

final class PermissionCatalogQuery
{
    /**
     * Return canonical machine-readable permission names.
     *
     * Permission catalog bersifat global. Query ini sengaja tidak:
     *
     * - membaca role,
     * - membaca membership,
     * - membaca tenant context,
     * - membaca organizational context,
     * - mengevaluasi authorization.
     *
     * @return array<int, string>
     */
    public function execute(): array
    {
        return Permission::query()
            ->select('name')
            ->orderBy('name')
            ->pluck('name')
            ->map(
                static fn(mixed $name): string =>
                trim((string) $name),
            )
            ->filter(
                static fn(string $name): bool =>
                $name !== '',
            )
            ->unique()
            ->values()
            ->all();
    }
}
