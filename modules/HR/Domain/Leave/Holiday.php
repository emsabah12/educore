<?php

namespace Modules\HR\Domain\Leave;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Hari libur (tanggal merah) kalender kerja HR untuk seluruh yayasan (PRD-001 §13.2). Terpisah dari
 * hari libur kalender akademik (PRD-002 §18 P4).
 *
 * Jangan membuat/mengubah langsung lewat model; pakai Modules\HR\Application\Leave\ManageWorkHolidays.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Holiday extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'hr_holidays';

    protected $fillable = [
        'tenant_id',
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
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** Jumlah hari kalender yang dicakup (termasuk tanggal mulai & selesai). */
    public function length(): int
    {
        return (int) $this->start_date->diffInDays($this->end_date) + 1;
    }
}
