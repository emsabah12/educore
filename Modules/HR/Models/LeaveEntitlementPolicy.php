<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Konfigurasi aturan pembangkitan entitlement tetap (HR-004 §7.2).
 * "This table is policy configuration, not employee balance."
 */
final class LeaveEntitlementPolicy extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const string PERIOD_BASIS_CALENDAR_YEAR = 'CALENDAR_YEAR';
    public const string PERIOD_BASIS_EMPLOYMENT_ANNIVERSARY = 'EMPLOYMENT_ANNIVERSARY';
    public const string PERIOD_BASIS_MANUAL = 'MANUAL';

    public const string CARRYOVER_NONE = 'NONE';
    public const string CARRYOVER_LIMITED = 'LIMITED';

    protected $table = 'leave_entitlement_policies';

    protected $attributes = [
        'carryover_mode' => self::CARRYOVER_NONE,
        'priority' => 0,
        'is_active' => true,
    ];

    protected $fillable = [
        'leave_type_id',
        'organization_id',
        'organization_unit_id',
        'employment_type_id',
        'employment_classification_id',
        'period_basis',
        'grant_units',
        'carryover_mode',
        'carryover_limit_units',
        'effective_from',
        'effective_to',
        'priority',
        'is_active',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'leave_type_id' => 'string',
        'organization_id' => 'string',
        'organization_unit_id' => 'string',
        'employment_type_id' => 'string',
        'employment_classification_id' => 'string',
        'grant_units' => 'decimal:2',
        'carryover_limit_units' => 'decimal:2',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'priority' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<LeaveType, $this>
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(
            LeaveType::class,
            'leave_type_id',
        );
    }

    /**
     * Skor spesifisitas scope untuk perankingan resolusi kebijakan
     * (§7.2 langkah 5): unit(2) > organization(1) > tenant-wide(0).
     */
    public function scopeSpecificity(): int
    {
        if ($this->organization_unit_id !== null) {
            return 2;
        }

        if ($this->organization_id !== null) {
            return 1;
        }

        return 0;
    }

    /**
     * Skor spesifisitas filter employment untuk perankingan resolusi
     * kebijakan (§7.2 langkah 6): makin banyak filter yang diisi
     * (employment_type + employment_classification), makin spesifik.
     */
    public function employmentFilterSpecificity(): int
    {
        return ($this->employment_type_id !== null ? 1 : 0)
            + ($this->employment_classification_id !== null ? 1 : 0);
    }
}
