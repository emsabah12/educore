<?php

namespace Modules\Academic\Domain\Classroom;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Academic\Domain\Calendar\AcademicYear;
use Modules\Core\Contracts\OwnedByOrganization;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Kelas / rombongan belajar di sebuah lembaga pada satu tahun ajaran (PRD-002 §4.2). Lembaganya =
 * node pemilik data untuk otorisasi per pohon lembaga. Nama class "SchoolClass" karena "Class"
 * adalah kata kunci PHP.
 *
 * Jangan membuat/mengubah langsung lewat model; pakai Modules\Academic\Application\Classroom\ManageClasses.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $organization_id
 * @property string $academic_year_id
 * @property int $grade
 * @property string $name
 * @property string|null $homeroom_employee_id
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Organization $organization
 * @property-read AcademicYear $academicYear
 */
class SchoolClass extends Model implements OwnedByOrganization
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'ac_classes';

    protected $fillable = [
        'tenant_id',
        'organization_id',
        'academic_year_id',
        'grade',
        'name',
        'homeroom_employee_id',
        'is_active',
    ];

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
            'grade' => 'integer',
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
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
