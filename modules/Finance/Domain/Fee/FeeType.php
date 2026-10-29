<?php

namespace Modules\Finance\Domain\Fee;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Jenis biaya siswa milik sebuah yayasan (PRD-003 §4), mis. SPP (bulanan), Daftar ulang (sekali).
 *
 * Jangan membuat/mengubah langsung lewat model; pakai Modules\Finance\Application\Fee\ManageFeeTypes.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property FeeKind $kind
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class FeeType extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'fn_fee_types';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'kind',
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
            'kind' => FeeKind::class,
            'is_active' => 'boolean',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}
