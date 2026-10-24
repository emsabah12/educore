<?php

namespace Modules\HR\Domain\Document;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Satu dokumen/SK pegawai beserta file-nya (PRD-001 §15, HR-2a).
 *
 * Jangan membuat/mengubah langsung lewat model; pakai Modules\HR\Application\Document\ManageEmployeeDocuments.
 * File tidak pernah diganti; dokumen yang salah dibatalkan (`voided_at`).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employee_id
 * @property string $document_type_id
 * @property string|null $number
 * @property string $title
 * @property CarbonImmutable|null $issued_on
 * @property CarbonImmutable|null $valid_from
 * @property CarbonImmutable|null $valid_until
 * @property string|null $note
 * @property string $storage_path
 * @property string $original_name
 * @property string $mime_type
 * @property int $byte_size
 * @property string $sha256
 * @property string|null $uploaded_by_membership_id
 * @property CarbonImmutable|null $voided_at
 * @property string|null $void_reason
 * @property string|null $voided_by_membership_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class EmployeeDocument extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'hr_documents';

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'document_type_id',
        'number',
        'title',
        'issued_on',
        'valid_from',
        'valid_until',
        'note',
        'storage_path',
        'original_name',
        'mime_type',
        'byte_size',
        'sha256',
        'uploaded_by_membership_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_on' => 'immutable_date',
            'valid_from' => 'immutable_date',
            'valid_until' => 'immutable_date',
            'byte_size' => 'integer',
            'voided_at' => 'immutable_datetime',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}
