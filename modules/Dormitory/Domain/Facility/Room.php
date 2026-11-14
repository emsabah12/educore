<?php

namespace Modules\Dormitory\Domain\Facility;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Core\Contracts\OwnedByOrganization;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Kamar (PRD-008 §4.3–§4.4). Kapasitas tidak disimpan; dihitung dari bed/loker yang tersedia menurut dasar
 * kapasitas (Application\Facility\RoomCapacity).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $organization_id
 * @property string $dormitory_id
 * @property string $building_id
 * @property string $code
 * @property string|null $name
 * @property CapacityBasis $capacity_basis
 * @property RoomComposition $composition
 * @property int $supervisor_slots
 * @property YearEndPolicy $year_end_policy
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Building $building
 * @property-read Dormitory $dormitory
 */
class Room extends Model implements OwnedByOrganization
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'dm_rooms';

    protected $fillable = [
        'tenant_id',
        'organization_id',
        'dormitory_id',
        'building_id',
        'code',
        'name',
        'capacity_basis',
        'composition',
        'supervisor_slots',
        'year_end_policy',
        'is_active',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'supervisor_slots' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity_basis' => CapacityBasis::class,
            'composition' => RoomComposition::class,
            'year_end_policy' => YearEndPolicy::class,
            'supervisor_slots' => 'integer',
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
     * @return BelongsTo<Building, $this>
     */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /**
     * @return BelongsTo<Dormitory, $this>
     */
    public function dormitory(): BelongsTo
    {
        return $this->belongsTo(Dormitory::class);
    }

    /**
     * @return HasMany<Bed, $this>
     */
    public function beds(): HasMany
    {
        return $this->hasMany(Bed::class);
    }

    /**
     * @return HasMany<Locker, $this>
     */
    public function lockers(): HasMany
    {
        return $this->hasMany(Locker::class);
    }
}
