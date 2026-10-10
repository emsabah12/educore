<?php

namespace Modules\Core\Domain\Organization;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Core\Contracts\OwnedByOrganization;
use Modules\Core\Domain\Tenancy\BelongsToTenant;
use Modules\Core\Domain\Tenancy\Tenant;

/**
 * Node pohon lembaga: LEMBAGA, UNIT, atau BIRO (PRD-000 §4).
 *
 * Jangan membuat/memindah node langsung lewat model ini, karena closure table
 * tidak akan ikut diperbarui. Pakai service di Modules\Core\Application\Organization.
 *
 * Otomatis tersaring ke yayasan aktif bila konteks kerja ada (BelongsToTenant).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $parent_id
 * @property OrganizationType $type
 * @property OrganizationCategory|null $category
 * @property Jenjang|null $jenjang
 * @property string $code
 * @property string $name
 * @property OrganizationStatus $status
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Organization extends Model implements OwnedByOrganization
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'parent_id',
        'type',
        'category',
        'jenjang',
        'code',
        'name',
        'status',
    ];

    /**
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
            'type' => OrganizationType::class,
            'category' => OrganizationCategory::class,
            'jenjang' => Jenjang::class,
            'status' => OrganizationStatus::class,
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    public function isActive(): bool
    {
        return $this->status === OrganizationStatus::Active;
    }

    /**
     * Sebuah node "dimiliki" oleh dirinya sendiri: Gate::authorize('core.organizations.view', $organization).
     */
    public function owningTenantId(): string
    {
        return $this->tenant_id;
    }

    public function owningOrganizationId(): string
    {
        return $this->id;
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Organization, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
