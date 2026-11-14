<?php

namespace Modules\Dormitory\Domain\Facility;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Core\Contracts\OwnedByOrganization;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Gedung di sebuah asrama (PRD-008 §4.1–§4.2). Jenis kelamin ditetapkan di gedung; semua kamarnya mengikuti.
 * `organization_id` adalah salinan pemilik asrama, dijaga sama oleh foreign key gabungan.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $organization_id
 * @property string $dormitory_id
 * @property string $code
 * @property string $name
 * @property Gender $gender
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Dormitory $dormitory
 */
class Building extends Model implements OwnedByOrganization
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'dm_buildings';

    protected $fillable = ['tenant_id', 'organization_id', 'dormitory_id', 'code', 'name', 'gender', 'is_active'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
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
     * @return BelongsTo<Dormitory, $this>
     */
    public function dormitory(): BelongsTo
    {
        return $this->belongsTo(Dormitory::class);
    }

    /**
     * @return HasMany<Room, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }
}
