<?php

namespace Modules\Academic\Domain\Classroom;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Core\Contracts\OwnedByOrganization;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Satu baris jadwal pelajaran mingguan sebuah kelas (PRD-002 §16 AC-3c): hari, jam mulai–selesai,
 * mapel kelas. Jam disimpan sebagai "HH:MM:SS" (kolom time).
 *
 * Jangan membuat/mengubah langsung lewat model; pakai Modules\Academic\Application\Classroom\ManageSchedule.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $organization_id
 * @property string $academic_year_id
 * @property string $class_id
 * @property string $class_subject_id
 * @property int $day_of_week
 * @property string $start_time
 * @property string $end_time
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ScheduleEntry extends Model implements OwnedByOrganization
{
    use BelongsToTenant;
    use HasUuids;

    /** Nama hari, 1 = Senin … 7 = Ahad. */
    public const DAYS = [
        1 => 'Senin',
        2 => 'Selasa',
        3 => 'Rabu',
        4 => 'Kamis',
        5 => 'Jumat',
        6 => 'Sabtu',
        7 => 'Ahad',
    ];

    protected $table = 'ac_schedule_entries';

    protected $fillable = [
        'tenant_id',
        'organization_id',
        'academic_year_id',
        'class_id',
        'class_subject_id',
        'day_of_week',
        'start_time',
        'end_time',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    public function owningTenantId(): string
    {
        return $this->tenant_id;
    }

    public function owningOrganizationId(): string
    {
        return $this->organization_id;
    }

    public static function dayName(int $day): string
    {
        return self::DAYS[$day] ?? '?';
    }

    /**
     * "07:00:00" → "07.00" (penulisan jam di Indonesia).
     */
    public static function displayTime(string $time): string
    {
        return str_replace(':', '.', substr($time, 0, 5));
    }
}
