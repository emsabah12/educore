<?php

namespace Modules\Academic\Domain\Subject;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Core\Contracts\OwnedByOrganization;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Kelompok mata pelajaran di sebuah lembaga (PRD-002 §22.4 AC-8b), mis. "Pelajaran Madrasah",
 * "Pelajaran Kepesantrenan". Menentukan urutan & penomoran di rapor, dan apakah nilai mapelnya ikut
 * jumlah & rata-rata rapor.
 *
 * Jangan membuat/mengubah langsung lewat model; pakai Modules\Academic\Application\Subject\ManageSubjectGroups.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $organization_id
 * @property string $name
 * @property int $sort_order
 * @property bool $counts_in_totals
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class SubjectGroup extends Model implements OwnedByOrganization
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'ac_subject_groups';

    protected $fillable = [
        'tenant_id',
        'organization_id',
        'name',
        'sort_order',
        'counts_in_totals',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sort_order' => 0,
        'counts_in_totals' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'counts_in_totals' => 'boolean',
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
