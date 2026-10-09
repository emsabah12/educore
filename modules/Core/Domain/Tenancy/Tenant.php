<?php

namespace Modules\Core\Domain\Tenancy;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Core\Domain\Organization\Organization;

/**
 * Tenant = yayasan/pelanggan; batas keamanan & isolasi data teratas (PRD-000 §5).
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property TenantStatus $status
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Tenant extends Model
{
    use HasUuids;

    protected $fillable = [
        'code',
        'name',
        'status',
    ];

    /**
     * Nilai default disamakan dengan default kolom di database,
     * supaya model yang baru dibuat langsung punya status yang benar.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'ACTIVE',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
        ];
    }

    /**
     * ID kanonik memakai UUIDv7 (PRD-000 §5): unik global dan berurutan waktu,
     * sehingga index B-tree tetap efisien.
     */
    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /**
     * @return HasMany<Organization, $this>
     */
    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }
}
