<?php

namespace Modules\Academic\Domain\Calendar;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Tahun ajaran di sebuah kalender (PRD-002 §4.2), mis. "2026/2027".
 * Maksimal satu tahun ajaran berjalan (is_current) per kalender.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $calendar_id
 * @property string $name
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property bool $is_current
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read AcademicCalendar $calendar
 */
class AcademicYear extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'ac_academic_years';

    protected $fillable = [
        'tenant_id',
        'calendar_id',
        'name',
        'start_date',
        'end_date',
        'is_current',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_current' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'is_current' => 'boolean',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /**
     * @return BelongsTo<AcademicCalendar, $this>
     */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(AcademicCalendar::class, 'calendar_id');
    }

    /**
     * @return HasMany<Term, $this>
     */
    public function terms(): HasMany
    {
        return $this->hasMany(Term::class, 'academic_year_id');
    }
}
