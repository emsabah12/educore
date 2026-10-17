<?php

namespace Modules\Academic\Domain\Calendar;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Kalender akademik milik sebuah yayasan (PRD-002 §4.4). Satu kalender default
 * ("Kalender Yayasan"); kalender lain dipakai lembaga lewat aturan `academic.calendar`.
 *
 * Jangan membuat/mengubah langsung lewat model; pakai service di
 * Modules\Academic\Application\Calendar agar validasi dan audit log ikut berjalan.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property bool $is_default
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class AcademicCalendar extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'ac_academic_calendars';

    protected $fillable = [
        'tenant_id',
        'name',
        'is_default',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_default' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /**
     * @return HasMany<AcademicYear, $this>
     */
    public function years(): HasMany
    {
        return $this->hasMany(AcademicYear::class, 'calendar_id');
    }
}
