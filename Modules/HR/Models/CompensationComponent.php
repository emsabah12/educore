<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Katalog tenant-scoped yang mendeskripsikan MAKNA sebuah fakta
 * kompensasi (mis. "BASE_SALARY", "TEACHING_HOUR_RATE") — HR-006
 * §7.2. Nilai spesifik per-employee adalah tanggung jawab
 * `CompensationAssignment`, bukan model ini.
 *
 * Sengaja TIDAK ada field taxability/accounting-code/BPJS-percentage/
 * PPh-formula — itu domain Finance (OD-HR-COMP-001).
 */
final class CompensationComponent extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const CATEGORY_BASE_PAY = 'BASE_PAY';
    public const CATEGORY_ALLOWANCE = 'ALLOWANCE';
    public const CATEGORY_RATE = 'RATE';
    public const CATEGORY_OTHER_EARNING_INPUT = 'OTHER_EARNING_INPUT';

    public const VALUE_MODE_FIXED_AMOUNT = 'FIXED_AMOUNT';
    public const VALUE_MODE_RATE_PER_UNIT = 'RATE_PER_UNIT';

    protected $table = 'compensation_components';

    protected $fillable = [
        'code',
        'name',
        'category',
        'value_mode',
        'unit_code',
        'periodicity',
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
}
