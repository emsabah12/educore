<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Katalog tenant-scoped program benefit (BPJS_KESEHATAN, TPG, THR,
 * dst. — kode contoh, BUKAN enum global wajib) — HR-006 §7.5.
 * Sengaja TIDAK ada kolom persentase kontribusi/formula pembayaran
 * statutori — itu domain Finance.
 */
final class BenefitProgram extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const CATEGORY_STATUTORY = 'STATUTORY';

    public const CATEGORY_GOVERNMENT = 'GOVERNMENT';

    public const CATEGORY_INSTITUTIONAL = 'INSTITUTIONAL';

    public const CATEGORY_OTHER = 'OTHER';

    public const BENEFICIARY_SCOPE_EMPLOYEE = 'EMPLOYEE';

    public const BENEFICIARY_SCOPE_DEPENDENT = 'DEPENDENT';

    public const BENEFICIARY_SCOPE_EITHER = 'EITHER';

    public const PAYROLL_RELEVANCE_NONE = 'NONE';

    public const PAYROLL_RELEVANCE_ELIGIBILITY_INPUT = 'ELIGIBILITY_INPUT';

    public const PAYROLL_RELEVANCE_EXTERNAL_PAYMENT_TRACKING = 'EXTERNAL_PAYMENT_TRACKING';

    protected $table = 'benefit_programs';

    protected $fillable = [
        'code',
        'name',
        'category',
        'beneficiary_scope',
        'payroll_relevance',
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
