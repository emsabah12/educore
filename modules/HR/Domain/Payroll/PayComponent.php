<?php

namespace Modules\HR\Domain\Payroll;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Komponen gaji milik sebuah yayasan (PRD-001 §16.1), mis. Gaji pokok, Uang transport, Iuran BPJS.
 *
 * Jangan membuat/mengubah langsung lewat model; pakai Modules\HR\Application\Payroll\ManagePayComponents.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property PayComponentKind $kind
 * @property PayComponentBasis $basis
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PayComponent extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'hr_pay_components';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'kind',
        'basis',
        'is_active',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PayComponentKind::class,
            'basis' => PayComponentBasis::class,
            'is_active' => 'boolean',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}
