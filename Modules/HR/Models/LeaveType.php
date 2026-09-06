<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Katalog Leave/Permit milik satu Tenant (HR-004 §7.1).
 *
 * "Once referenced by entitlement/ledger/request history, category,
 * balance_mode, and unit must not be mutated in ways that reinterpret
 * historical data." — penegakan ada di service layer, model ini murni
 * struktur data.
 */
final class LeaveType extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const string CATEGORY_LEAVE = 'LEAVE';
    public const string CATEGORY_PERMIT = 'PERMIT';

    public const string BALANCE_MODE_BALANCE = 'BALANCE';
    public const string BALANCE_MODE_NONE = 'NONE';

    public const string UNIT_DAY = 'DAY';
    public const string UNIT_HOUR = 'HOUR';

    protected $table = 'leave_types';

    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'code',
        'name',
        'category',
        'balance_mode',
        'unit',
        'description',
        'is_active',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'is_active' => 'boolean',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public function isBalanceBacked(): bool
    {
        return $this->balance_mode === self::BALANCE_MODE_BALANCE;
    }
}
