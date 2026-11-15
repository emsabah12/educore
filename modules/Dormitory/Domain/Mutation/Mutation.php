<?php

namespace Modules\Dormitory\Domain\Mutation;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Core\Contracts\OwnedByOrganization;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Mutasi kamar (PRD-008 §8, §18). Pemilik untuk otorisasi = node pemilik asrama ASAL; hak di asrama tujuan dicek
 * terpisah (§18.2 butir 3). Jangan membuat/mengubah langsung; pakai Modules\Dormitory\Application\Mutation\ManageMutations.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $organization_id
 * @property string $dormitory_id
 * @property string $from_room_id
 * @property string $placement_id
 * @property string $to_organization_id
 * @property string $to_dormitory_id
 * @property string $to_room_id
 * @property string $membership_id
 * @property MutationType $type
 * @property MutationStatus $status
 * @property EmergencyCategory|null $emergency_category
 * @property string $reason
 * @property string|null $requested_by
 * @property string|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property CarbonImmutable|null $executed_on
 * @property string|null $new_placement_id
 * @property string|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $review_note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Mutation extends Model implements OwnedByOrganization
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'dm_mutations';

    protected $fillable = [
        'tenant_id',
        'organization_id',
        'dormitory_id',
        'from_room_id',
        'placement_id',
        'to_organization_id',
        'to_dormitory_id',
        'to_room_id',
        'membership_id',
        'type',
        'status',
        'emergency_category',
        'reason',
        'requested_by',
        'decided_by',
        'decided_at',
        'decision_note',
        'executed_on',
        'new_placement_id',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MutationType::class,
            'status' => MutationStatus::class,
            'emergency_category' => EmergencyCategory::class,
            'decided_at' => 'immutable_datetime',
            'executed_on' => 'immutable_date',
            'reviewed_at' => 'immutable_datetime',
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
}
