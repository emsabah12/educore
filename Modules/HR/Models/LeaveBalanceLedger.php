<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Satu baris ledger saldo entitlement — APPEND-ONLY (HR-004 §7.4).
 *
 * "ledger rows are not updated/deleted through normal application
 * APIs; corrections use a new compensating entry." Model ini SENGAJA
 * tidak punya `updated_at` (const UPDATED_AT = null) — mencerminkan
 * bahwa baris di sini tidak pernah dianggap "diperbarui".
 */
final class LeaveBalanceLedger extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const string ENTRY_GRANT = 'GRANT';

    public const string ENTRY_CARRYOVER_IN = 'CARRYOVER_IN';

    public const string ENTRY_CARRYOVER_OUT = 'CARRYOVER_OUT';

    public const string ENTRY_ADJUSTMENT = 'ADJUSTMENT';

    public const string ENTRY_CONSUME = 'CONSUME';

    public const string ENTRY_RESTORE = 'RESTORE';

    public const string ENTRY_EXPIRE = 'EXPIRE';

    public const string ENTRY_REVERSAL = 'REVERSAL';

    public const ?string UPDATED_AT = null;

    protected $table = 'leave_balance_ledger';

    protected $fillable = [
        'entitlement_id',
        'entry_type',
        'units_delta',
        'leave_request_id',
        'reverses_entry_id',
        'idempotency_key',
        'actor_membership_id',
        'note',
        'occurred_at',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'entitlement_id' => 'string',
        'units_delta' => 'decimal:2',
        'leave_request_id' => 'string',
        'reverses_entry_id' => 'string',
        'actor_membership_id' => 'string',
        'occurred_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<LeaveEntitlement, $this>
     */
    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(
            LeaveEntitlement::class,
            'entitlement_id',
        );
    }
}
