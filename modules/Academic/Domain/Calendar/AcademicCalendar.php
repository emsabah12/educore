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
 * @property array<int, mixed> $weekly_off_days nomor hari libur mingguan 1 (Senin) – 7 (Ahad), PRD-002 §18 P4; baca lewat ManageHolidays::weeklyOffDaysOf()
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class AcademicCalendar extends Model
{
    use BelongsToTenant;
    use HasUuids;

    /** Hari libur mingguan bawaan: Ahad. */
    public const DEFAULT_WEEKLY_OFF_DAYS = [7];

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
        'weekly_off_days' => '[7]',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'weekly_off_days' => 'array',
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
