<?php

namespace Modules\Academic\Domain\Calendar;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Semester/caturwulan di dalam tahun ajaran (PRD-002 §4.2). 1–4 per tahun ajaran, berurutan.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $academic_year_id
 * @property int $sequence
 * @property string $name
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Term extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'ac_terms';

    protected $fillable = [
        'tenant_id',
        'academic_year_id',
        'sequence',
        'name',
        'start_date',
        'end_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}
