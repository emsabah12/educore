<?php

namespace Modules\Academic\Domain\Grading;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Core\Contracts\OwnedByOrganization;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Butir rapor non-akademik satu lembaga (PRD-002 §22.6 AC-8d): kegiatan ekstrakurikuler atau aspek
 * kepribadian (dengan keterangan otomatis per huruf predikat).
 *
 * Jangan membuat/mengubah langsung lewat model; pakai Modules\Academic\Application\Grading\ReportItems.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $organization_id
 * @property string $kind self::EXTRACURRICULAR atau self::PERSONALITY
 * @property string $name
 * @property int $sort_order
 * @property array<string, string>|null $descriptions huruf → keterangan (kepribadian)
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ReportItem extends Model implements OwnedByOrganization
{
    use BelongsToTenant;
    use HasUuids;

    public const EXTRACURRICULAR = 'EKSKUL';

    public const PERSONALITY = 'KEPRIBADIAN';

    protected $table = 'ac_report_items';

    protected $fillable = [
        'tenant_id',
        'organization_id',
        'kind',
        'name',
        'sort_order',
        'descriptions',
        'is_active',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sort_order' => 0,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'descriptions' => 'array',
            'is_active' => 'boolean',
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
}
