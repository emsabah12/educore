<?php

namespace Modules\Core\Domain\Authorization;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Satu hak akses di katalog global, key `modul.resource.aksi` (PRD-000 §5).
 *
 * Isi tabel ini disinkronkan dari kode (AccessCatalog); jangan diubah manual.
 *
 * @property string $id
 * @property string $key
 * @property string $name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Permission extends Model
{
    use HasUuids;

    protected $fillable = [
        'key',
        'name',
    ];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}
