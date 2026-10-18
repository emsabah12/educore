<?php

namespace Modules\Academic\Domain\Student;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Core\Contracts\OwnedByOrganization;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Pendaftaran siswa di sebuah lembaga (PRD-002 §4.2). Lembaga ini = node pemilik data untuk
 * penyaringan per pohon lembaga (§5.3). Kosong end_date = masih terdaftar.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $student_id
 * @property string $organization_id
 * @property string|null $student_number
 * @property EntryType $entry_type
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable|null $end_date
 * @property EnrollmentEndReason|null $end_reason
 * @property string|null $end_note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Student $student
 * @property-read Organization $organization
 */
class Enrollment extends Model implements OwnedByOrganization
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'ac_enrollments';

    protected $fillable = [
        'tenant_id',
        'student_id',
        'organization_id',
        'student_number',
        'entry_type',
        'start_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_type' => EntryType::class,
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'end_reason' => EnrollmentEndReason::class,
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
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isOpen(): bool
    {
        return $this->end_date === null;
    }
}
