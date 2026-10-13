<?php

namespace Modules\HR\Domain\Employee;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Core\Domain\Tenancy\BelongsToTenant;
use Modules\Core\Domain\Tenancy\Membership;

/**
 * Profil pegawai di sebuah yayasan (PRD-001 §4.3). Nama & data pribadi ada di Person
 * (lewat membership); di sini hanya data kepegawaian.
 *
 * Jangan membuat/mengubah langsung lewat model; pakai service di Modules\HR\Application\Employee.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $membership_id
 * @property string|null $employee_number
 * @property string|null $nuptk
 * @property EducationLevel|null $education_level
 * @property string|null $education_major
 * @property EmployeeStatus $status
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Membership $membership
 */
class Employee extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'hr_employees';

    protected $fillable = [
        'tenant_id',
        'membership_id',
        'employee_number',
        'nuptk',
        'education_level',
        'education_major',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'AKTIF',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EmployeeStatus::class,
            'education_level' => EducationLevel::class,
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /**
     * @return HasMany<EmploymentPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(EmploymentPeriod::class);
    }

    /**
     * @return HasMany<Placement, $this>
     */
    public function placements(): HasMany
    {
        return $this->hasMany(Placement::class);
    }
}
