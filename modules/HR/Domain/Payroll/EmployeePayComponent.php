<?php

namespace Modules\HR\Domain\Payroll;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Nominal satu komponen gaji untuk seorang pegawai (PRD-001 §16.1), rupiah bulat.
 *
 * Jangan membuat/mengubah langsung lewat model; pakai Modules\HR\Application\Payroll\EmployeePay.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employee_id
 * @property string $pay_component_id
 * @property int $amount
 * @property string|null $updated_by_membership_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class EmployeePayComponent extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'hr_employee_pay_components';

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'pay_component_id',
        'amount',
        'updated_by_membership_id',
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
