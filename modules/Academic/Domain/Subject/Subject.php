<?php

namespace Modules\Academic\Domain\Subject;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Core\Contracts\OwnedByOrganization;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Mata pelajaran di sebuah lembaga (PRD-002 §16 AC-3a). Lembaganya = node pemilik data untuk
 * otorisasi per pohon lembaga.
 *
 * Jangan membuat/mengubah langsung lewat model; pakai Modules\Academic\Application\Subject\ManageSubjects.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $organization_id
 * @property string $name
 * @property string|null $code
 * @property int|null $grade_min
 * @property int|null $grade_max
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Organization $organization
 */
class Subject extends Model implements OwnedByOrganization
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'ac_subjects';

    protected $fillable = [
        'tenant_id',
        'organization_id',
        'name',
        'code',
        'grade_min',
        'grade_max',
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
            'grade_min' => 'integer',
            'grade_max' => 'integer',
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
     * Rentang tingkat memuat tingkat ini (kosong = semua tingkat).
     */
    public function coversGrade(int $grade): bool
    {
        return $this->grade_min === null || $this->grade_max === null
            || ($grade >= $this->grade_min && $grade <= $this->grade_max);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
