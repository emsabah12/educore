<?php

namespace Modules\Academic\Domain\Grading;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Core\Contracts\OwnedByOrganization;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Format rapor satu lembaga (PRD-002 §22.5 AC-8c).
 *
 * Jangan membuat/mengubah langsung lewat model; pakai Modules\Academic\Application\Grading\ReportFormats.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $organization_id
 * @property string|null $header_line1
 * @property string|null $header_line2
 * @property string|null $header_line3
 * @property string|null $header_line4
 * @property string|null $logo_path path di disk privat `local`
 * @property string|null $logo_mime image/png atau image/jpeg
 * @property string $title
 * @property string $student_term SISWA | SANTRI | MURID
 * @property string|null $place
 * @property bool $show_hijri
 * @property string $principal_title
 * @property string|null $principal_name
 * @property string|null $witness_title
 * @property string|null $witness_name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ReportFormat extends Model implements OwnedByOrganization
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'ac_report_formats';

    protected $fillable = [
        'tenant_id',
        'organization_id',
        'header_line1',
        'header_line2',
        'header_line3',
        'header_line4',
        'logo_path',
        'logo_mime',
        'title',
        'student_term',
        'place',
        'show_hijri',
        'principal_title',
        'principal_name',
        'witness_title',
        'witness_name',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['show_hijri' => 'boolean'];
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
