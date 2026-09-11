<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Input HR earning/koreksi SEKALI-WAKTU yang sudah disetujui —
 * HR-006 §7.8. BUKAN tabel deduksi generik. `amount` SELALU positif
 * (HR tidak pernah membuat baris adjustment negatif — koreksi yang
 * MENGURANGI payable tetap domain Finance).
 *
 * Maker-checker WAJIB ditegakkan DB (lihat migration): satu
 * membership tidak bisa jadi requester sekaligus approver baris yang
 * sama. Business logic (transisi status, penegakan maker-checker di
 * level aplikasi juga sebagai defense-in-depth) akan dibangun sebagai
 * service pada step berikutnya — model ini murni struktur data &
 * relasi.
 */
final class CompensationAdjustment extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const TYPE_ONE_TIME_EARNING = 'ONE_TIME_EARNING';
    public const TYPE_COMPENSATION_CORRECTION = 'COMPENSATION_CORRECTION';

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_SUBMITTED = 'SUBMITTED';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_CANCELLED = 'CANCELLED';

    protected $table = 'compensation_adjustments';

    protected $fillable = [
        'employment_id',
        'compensation_component_id',
        'adjustment_type',
        'amount',
        'currency_code',
        'target_period_start',
        'target_period_end',
        'status',
        'reason',
        'requested_by_membership_id',
        'approved_by_membership_id',
        'approved_at',
        'idempotency_key',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'employment_id' => 'string',
        'compensation_component_id' => 'string',
        'amount' => 'decimal:4',
        'target_period_start' => 'date',
        'target_period_end' => 'date',
        'requested_by_membership_id' => 'string',
        'approved_by_membership_id' => 'string',
        'approved_at' => 'immutable_datetime',
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
     * @return BelongsTo<Membership, $this>
     */
    public function requestedByMembership(): BelongsTo
    {
        return $this->belongsTo(
            Membership::class,
            'requested_by_membership_id',
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
