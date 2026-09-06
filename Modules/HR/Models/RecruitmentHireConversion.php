<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Rekaman semantic-idempotency dan bukti hasil konversi hiring
 * (HR-003 §7.14).
 *
 * "Repeated successful conversion requests return the existing
 * successful result rather than creating new domain rows" —
 * HireConversionService SELALU lock/create baris ini lebih dulu
 * sebelum menyentuh Person/Membership/Employee/Employment apa pun.
 */
final class RecruitmentHireConversion extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const string RESOLUTION_UNRESOLVED = 'UNRESOLVED';
    public const string RESOLUTION_MATCHED_EXISTING = 'MATCHED_EXISTING';
    public const string RESOLUTION_CREATE_NEW_CONFIRMED = 'CREATE_NEW_CONFIRMED';
    public const string RESOLUTION_CONFLICT = 'CONFLICT';

    public const string CONVERSION_PENDING = 'PENDING';
    public const string CONVERSION_SUCCEEDED = 'SUCCEEDED';
    public const string CONVERSION_CANCELLED = 'CANCELLED';

    protected $table = 'recruitment_hire_conversions';

    protected $attributes = [
        'resolution_status' => self::RESOLUTION_UNRESOLVED,
        'conversion_status' => self::CONVERSION_PENDING,
    ];

    protected $fillable = [
        'application_id',
        'resolution_status',
        'conversion_status',
        'person_id',
        'membership_id',
        'employee_id',
        'employment_id',
        'resolved_by_membership_id',
        'converted_by_membership_id',
        'converted_at',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'application_id' => 'string',
        'person_id' => 'string',
        'membership_id' => 'string',
        'employee_id' => 'string',
        'employment_id' => 'string',
        'resolved_by_membership_id' => 'string',
        'converted_by_membership_id' => 'string',
        'converted_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<RecruitmentApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(
            RecruitmentApplication::class,
            'application_id',
        );
    }
}
