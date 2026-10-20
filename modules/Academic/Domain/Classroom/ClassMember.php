<?php

namespace Modules\Academic\Domain\Classroom;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Academic\Domain\Student\Enrollment;
use Modules\Core\Contracts\OwnedByOrganization;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Keanggotaan kelas (PRD-002 §4.2): pendaftaran siswa di sebuah kelas. Kosong end_date = masih di
 * kelas ini. organization_id & academic_year_id disalin dari kelasnya agar database menjamin kelas
 * dan pendaftaran di lembaga yang sama, serta satu kelas terbuka per tahun ajaran.
 *
 * Jangan membuat/mengubah langsung lewat model; pakai Modules\Academic\Application\Classroom\ManageClassMembers.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $organization_id
 * @property string $academic_year_id
 * @property string $class_id
 * @property string $enrollment_id
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable|null $end_date
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read SchoolClass $schoolClass
 * @property-read Enrollment $enrollment
 */
class ClassMember extends Model implements OwnedByOrganization
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'ac_class_members';

    protected $fillable = [
        'tenant_id',
        'organization_id',
        'academic_year_id',
        'class_id',
        'enrollment_id',
        'start_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function isOpen(): bool
    {
        return $this->end_date === null;
    }
}
