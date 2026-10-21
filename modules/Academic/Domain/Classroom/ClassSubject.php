<?php

namespace Modules\Academic\Domain\Classroom;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Core\Contracts\OwnedByOrganization;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Mata pelajaran yang diajarkan di sebuah kelas beserta guru pengampunya (PRD-002 §16 AC-3b).
 *
 * Jangan membuat/mengubah langsung lewat model; pakai Modules\Academic\Application\Classroom\ManageClassSubjects.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $organization_id
 * @property string $academic_year_id
 * @property string $class_id
 * @property string $subject_id
 * @property string|null $teacher_employee_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ClassSubject extends Model implements OwnedByOrganization
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'ac_class_subjects';

    protected $fillable = [
        'tenant_id',
        'organization_id',
        'academic_year_id',
        'class_id',
        'subject_id',
        'teacher_employee_id',
    ];

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
