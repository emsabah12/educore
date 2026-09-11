<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Fakta kompensasi effective-dated untuk satu Employment — HR-006
 * §7.3. `employment_position_assignment_id` OPSIONAL: null berarti
 * fakta tingkat-Employment (mis. gaji pokok); terisi berarti terikat
 * ke satu Position Assignment tertentu (mis. tunjangan jabatan).
 *
 * "Approved-history rule": setelah APPROVED, field nilai immutable
 * lewat API update biasa — koreksi membuat baris BARU dengan
 * `supersedes_assignment_id` menunjuk baris lama, baris lama
 * ditandai SUPERSEDED. Business logic (transisi status, validasi
 * value_mode vs amount/rate) akan dibangun sebagai
 * CompensationAssignmentService pada step berikutnya — model ini
 * murni struktur data & relasi.
 */
final class CompensationAssignment extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_ENDED = 'ENDED';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_SUPERSEDED = 'SUPERSEDED';

    protected $table = 'compensation_assignments';

    protected $fillable = [
        'employment_id',
        'compensation_component_id',
        'employment_position_assignment_id',
        'status',
        'amount',
        'rate',
        'currency_code',
        'effective_from',
        'effective_to',
        'supersedes_assignment_id',
        'approved_by_membership_id',
        'approved_at',
        'ended_at',
        'reason',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'employment_id' => 'string',
        'compensation_component_id' => 'string',
        'employment_position_assignment_id' => 'string',
        'amount' => 'decimal:4',
        'rate' => 'decimal:4',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'supersedes_assignment_id' => 'string',
        'approved_by_membership_id' => 'string',
        'approved_at' => 'immutable_datetime',
        'ended_at' => 'immutable_datetime',
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
     * @return BelongsTo<CompensationComponent, $this>
     */
    public function compensationComponent(): BelongsTo
    {
        return $this->belongsTo(
            CompensationComponent::class,
            'compensation_component_id',
        );
    }

    /**
     * @return BelongsTo<EmploymentPositionAssignment, $this>
     */
    public function employmentPositionAssignment(): BelongsTo
    {
        return $this->belongsTo(
            EmploymentPositionAssignment::class,
            'employment_position_assignment_id',
        );
    }

    /**
     * @return BelongsTo<CompensationAssignment, $this>
     */
    public function supersedesAssignment(): BelongsTo
    {
        return $this->belongsTo(
            self::class,
            'supersedes_assignment_id',
        );
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function approvedByMembership(): BelongsTo
    {
        return $this->belongsTo(
            Membership::class,
            'approved_by_membership_id',
        );
    }
}
