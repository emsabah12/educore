<?php

namespace Modules\Core\Domain\Settings;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Satu nilai aturan berjenjang (PRD-000 §5, §8).
 *
 * Jangan menulis langsung lewat model ini; pakai SetScopedSetting agar
 * hak akses, validasi nilai, dan kunci dari atas selalu dicek.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $organization_id
 * @property Jenjang|null $jenjang
 * @property string $key
 * @property mixed $value
 * @property bool $is_enforced
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ScopedSetting extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'organization_id',
        'jenjang',
        'key',
        'value',
        'is_enforced',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'jenjang' => Jenjang::class,
            'value' => 'json',
            'is_enforced' => 'boolean',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}
