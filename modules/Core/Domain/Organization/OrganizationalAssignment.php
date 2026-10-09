<?php

namespace Modules\Core\Domain\Organization;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Core\Domain\Tenancy\BelongsToTenant;
use Modules\Core\Domain\Tenancy\Membership;

/**
 * Penugasan seorang anggota di pohon lembaga (PRD-000 §4.4, §5).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $membership_id
 * @property string|null $organization_id
 * @property Jenjang|null $jenjang_filter
 * @property AssignmentStatus $status
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Organization|null $organization
 * @property-read Membership $membership
 */
class OrganizationalAssignment extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'membership_id',
        'organization_id',
        'jenjang_filter',
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
            'jenjang_filter' => Jenjang::class,
            'status' => AssignmentStatus::class,
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    public function isActive(): bool
    {
        return $this->status === AssignmentStatus::Active;
    }

    /**
     * Penugasan fungsional: membina semua lembaga berjenjang tertentu (PRD-000 §4.4).
     */
    public function isFunctional(): bool
    {
        return $this->jenjang_filter !== null;
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }
}
