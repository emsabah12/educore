<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Identitas/profil pelamar sebelum konversi ke Person canonical
 * (HR-003 §7.4).
 *
 * INV-REC-001 (LOCKED) — "Candidate does not imply Person": `person_id`
 * NULLABLE dan HANYA diisi lewat hiring conversion (Fase E). Selama
 * kandidat masih dalam proses seleksi, model ini TIDAK PERNAH
 * merepresentasikan baris di `persons`/`memberships` Core.
 */
final class RecruitmentCandidate extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const string STATUS_ACTIVE = 'ACTIVE';
    public const string STATUS_ARCHIVED = 'ARCHIVED';

    protected $table = 'recruitment_candidates';

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected $fillable = [
        'person_id',
        'display_name',
        'birth_date',
        'primary_email',
        'normalized_email',
        'primary_phone',
        'normalized_phone',
        'source',
        'status',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'person_id' => 'string',
        'birth_date' => 'date',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return HasMany<RecruitmentCandidateIdentifier, $this>
     */
    public function identifiers(): HasMany
    {
        return $this->hasMany(
            RecruitmentCandidateIdentifier::class,
            'candidate_id',
        );
    }
}
