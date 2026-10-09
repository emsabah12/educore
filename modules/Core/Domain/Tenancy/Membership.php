<?php

namespace Modules\Core\Domain\Tenancy;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Core\Domain\Organization\OrganizationalAssignment;
use Modules\Core\Domain\Person\Person;

/**
 * Keikutsertaan seorang Person di sebuah yayasan (PRD-000 §5).
 *
 * Sengaja TIDAK memakai filter tenant otomatis: model ini justru dipakai untuk
 * memilih yayasan, sehingga harus bisa dibaca lintas yayasan milik orang yang sama.
 *
 * @property string $id
 * @property string $person_id
 * @property string $tenant_id
 * @property MembershipStatus $status
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Person $person
 */
class Membership extends Model
{
    use HasUuids;

    protected $fillable = [
        'person_id',
        'tenant_id',
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
            'status' => MembershipStatus::class,
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return HasMany<OrganizationalAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(OrganizationalAssignment::class);
    }
}
