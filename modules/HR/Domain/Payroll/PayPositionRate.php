<?php

namespace Modules\HR\Domain\Payroll;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Tarif komponen gaji "Menurut jabatan" untuk satu jabatan (PRD-001 §16.1, HR-5a-2), berlaku di semua
 * lembaga (`organization_id` null) atau satu lembaga tertentu (didahulukan).
 *
 * Jangan membuat/mengubah langsung lewat model; pakai Modules\HR\Application\Payroll\ManagePositionRates.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $pay_component_id
 * @property string $position_id
 * @property string|null $organization_id
 * @property int $amount
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PayPositionRate extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'hr_pay_position_rates';

    protected $fillable = [
        'tenant_id',
        'pay_component_id',
        'position_id',
        'organization_id',
        'amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}
