<?php

namespace Modules\HR\Domain\Employee;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Core\Domain\Tenancy\BelongsToTenant;
use Modules\HR\Domain\Catalog\EmploymentClassification;
use Modules\HR\Domain\Catalog\EmploymentType;

/**
 * Periode kepegawaian (PRD-001 §4.3). end_date kosong = periode yang sedang berjalan.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employee_id
 * @property string $employment_type_id
 * @property string|null $classification_id
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable|null $end_date
 * @property EndReason|null $end_reason
 * @property string|null $end_note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class EmploymentPeriod extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'hr_employment_periods';

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'employment_type_id',
        'classification_id',
        'start_date',
        'end_date',
        'end_reason',
        'end_note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'end_reason' => EndReason::class,
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<EmploymentType, $this>
     */
    public function employmentType(): BelongsTo
    {
        return $this->belongsTo(EmploymentType::class);
    }

    /**
     * @return BelongsTo<EmploymentClassification, $this>
     */
    public function classification(): BelongsTo
    {
        return $this->belongsTo(EmploymentClassification::class);
    }
}
