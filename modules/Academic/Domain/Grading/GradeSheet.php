<?php

namespace Modules\Academic\Domain\Grading;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Core\Contracts\OwnedByOrganization;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Lembar nilai satu mapel kelas pada satu semester (PRD-002 §19 AC-5b), dengan salinan aturan
 * penilaian saat pertama kali disimpan.
 *
 * Jangan membuat/mengubah langsung lewat model; pakai Modules\Academic\Application\Grading\ManageGrades.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $organization_id
 * @property string $academic_year_id
 * @property string $class_id
 * @property string $class_subject_id
 * @property string $term_id
 * @property string $status
 * @property array<string, mixed> $rules
 * @property string|null $finalized_by_membership_id
 * @property CarbonImmutable|null $finalized_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class GradeSheet extends Model implements OwnedByOrganization
{
    use BelongsToTenant;
    use HasUuids;

    public const DRAFT = 'DRAFT';

    public const FINAL = 'FINAL';

    protected $table = 'ac_grade_sheets';

    protected $fillable = [
        'tenant_id',
        'organization_id',
        'academic_year_id',
        'class_id',
        'class_subject_id',
        'term_id',
        'status',
        'rules',
        'finalized_by_membership_id',
        'finalized_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'finalized_at' => 'immutable_datetime',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    public function isFinal(): bool
    {
        return $this->status === self::FINAL;
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
