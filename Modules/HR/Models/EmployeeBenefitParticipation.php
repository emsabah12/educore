<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Person\Models\PersonModel;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Melacak eligibility/enrollment/participation Employee (atau
 * dependent-nya) ke satu Benefit Program — HR-006 §7.6. BUKAN
 * settlement moneter final (domain Finance).
 *
 * `beneficiary_person_id` null berarti Employee sendiri yang jadi
 * beneficiary; terisi berarti dependent/Person lain (referensi Core
 * `Person` langsung — tidak ada duplikasi identitas anak/tanggungan
 * di HR, lihat RISK R-007 di PRD).
 *
 * Business logic (transisi status, verifikasi) akan dibangun sebagai
 * service pada step berikutnya — model ini murni struktur data &
 * relasi.
 */
final class EmployeeBenefitParticipation extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const STATUS_ELIGIBLE = 'ELIGIBLE';
    public const STATUS_ENROLLED = 'ENROLLED';
    public const STATUS_SUSPENDED = 'SUSPENDED';
    public const STATUS_ENDED = 'ENDED';
    public const STATUS_INELIGIBLE = 'INELIGIBLE';

    protected $table = 'employee_benefit_participations';

    protected $fillable = [
        'employment_id',
        'benefit_program_id',
        'beneficiary_person_id',
        'status',
        'effective_from',
        'effective_to',
        'verified_at',
        'verified_by_membership_id',
        'notes',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'employment_id' => 'string',
        'benefit_program_id' => 'string',
        'beneficiary_person_id' => 'string',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'verified_at' => 'immutable_datetime',
        'verified_by_membership_id' => 'string',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<Employment, $this>
     */
    public function employment(): BelongsTo
    {
        return $this->belongsTo(
            Employment::class,
            'employment_id',
        );
    }

    /**
     * @return BelongsTo<BenefitProgram, $this>
     */
    public function benefitProgram(): BelongsTo
    {
        return $this->belongsTo(
            BenefitProgram::class,
            'benefit_program_id',
        );
    }

    /**
     * @return BelongsTo<PersonModel, $this>
     */
    public function beneficiaryPerson(): BelongsTo
    {
        return $this->belongsTo(
            PersonModel::class,
            'beneficiary_person_id',
        );
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function verifiedByMembership(): BelongsTo
    {
        return $this->belongsTo(
            Membership::class,
            'verified_by_membership_id',
        );
    }
}
