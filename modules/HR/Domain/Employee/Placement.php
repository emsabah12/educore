<?php

namespace Modules\HR\Domain\Employee;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Core\Contracts\OwnedByOrganization;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Tenancy\BelongsToTenant;
use Modules\HR\Domain\Catalog\Position;

/**
 * Penempatan pegawai: jabatan di sebuah lembaga (PRD-001 §4.3). Node pemiliknya dipakai
 * untuk penyaringan per pohon lembaga (§5.3). Jabatan bukan hak akses.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employee_id
 * @property string $organization_id
 * @property string $position_id
 * @property bool $is_primary
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable|null $end_date
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Placement extends Model implements OwnedByOrganization
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'hr_placements';

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'organization_id',
        'position_id',
        'is_primary',
        'start_date',
        'end_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
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
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Position, $this>
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }
}
