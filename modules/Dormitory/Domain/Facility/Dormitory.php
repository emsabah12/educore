<?php

namespace Modules\Dormitory\Domain\Facility;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Core\Contracts\OwnedByOrganization;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Asrama (PRD-008 §4.1): dimiliki tepat satu node LEMBAGA/UNIT. Node pemilik = dasar otorisasi untuk asrama
 * beserta seluruh gedung, kamar, bed, dan lokernya.
 *
 * Jangan membuat/mengubah langsung lewat model; pakai Modules\Dormitory\Application\Facility\ManageDormitories.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $organization_id
 * @property string $code
 * @property string $name
 * @property YearEndPolicy $default_year_end_policy
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Organization $organization
 */
class Dormitory extends Model implements OwnedByOrganization
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'dm_dormitories';

    protected $fillable = ['tenant_id', 'organization_id', 'code', 'name', 'default_year_end_policy', 'is_active'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'default_year_end_policy' => 'TETAP',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_year_end_policy' => YearEndPolicy::class,
            'is_active' => 'boolean',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    public function owningTenantId(): string
    {
        return $this->tenant_id;
    }

    public function owningOrganizationId(): string
    {
        return $this->organization_id;
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<Building, $this>
     */
    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class);
    }
}
