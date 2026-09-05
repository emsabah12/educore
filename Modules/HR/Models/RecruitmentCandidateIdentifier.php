<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Klaim identitas kuat milik seorang Candidate, dipakai untuk duplicate
 * detection & resolusi Person canonical (HR-003 §7.5).
 *
 * PENTING: `encrypted_value` dan `value_fingerprint` SENGAJA TIDAK ADA
 * di $fillable — satu-satunya jalur penulisan adalah lewat
 * RecruitmentCandidateIdentifierRepository, yang memanggil
 * `PersonIdentifierCipherInterface` (dipakai ulang dari Core, BUKAN
 * primitif kriptografi baru). Ini mencegah raw legal identifier
 * ter-mass-assign secara tidak sengaja lewat request payload mentah.
 */
final class RecruitmentCandidateIdentifier extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const string STATUS_ACTIVE = 'ACTIVE';
    public const string STATUS_REVOKED = 'REVOKED';

    protected $table = 'recruitment_candidate_identifiers';

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected $fillable = [
        'candidate_id',
        'type',
        'issuing_country_code',
        'verified_at',
        'status',
    ];

    /**
     * Ciphertext TIDAK PERNAH boleh muncul di serialisasi JSON default
     * — mencegah kebocoran tidak sengaja lewat response API mana pun
     * yang lupa meng-exclude kolom ini secara eksplisit.
     *
     * @var list<string>
     */
    protected $hidden = [
        'encrypted_value',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'candidate_id' => 'string',
        'verified_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<RecruitmentCandidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(
            RecruitmentCandidate::class,
            'candidate_id',
        );
    }
}
