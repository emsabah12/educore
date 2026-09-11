<?php

declare(strict_types=1);

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Uuid\HasUuidV7;
use Modules\Core\Tenancy\Traits\BelongsToTenant;

/**
 * Identifier domain-benefit (mis. nomor peserta BPJS) milik satu
 * EmployeeBenefitParticipation — HR-006 §7.7.
 *
 * PENTING: `encrypted_value` dan `value_fingerprint` SENGAJA TIDAK
 * ADA di $fillable — satu-satunya jalur penulisan adalah lewat
 * EloquentEmployeeBenefitIdentifierRepository, yang memanggil
 * `PersonIdentifierCipherInterface` (dipakai ulang dari Core, BUKAN
 * primitif kriptografi baru). Pola ini identik
 * `RecruitmentCandidateIdentifier` (HR-003 §7.5).
 */
final class EmployeeBenefitIdentifier extends Model
{
    use BelongsToTenant;
    use HasUuidV7;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_INACTIVE = 'INACTIVE';

    protected $table = 'employee_benefit_identifiers';

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected $fillable = [
        'employee_benefit_participation_id',
        'benefit_program_id',
        'identifier_type',
        'issuer',
        'issued_at',
        'expires_at',
        'status',
    ];

    /**
     * Ciphertext TIDAK PERNAH boleh muncul di serialisasi JSON
     * default — mencegah kebocoran tidak sengaja lewat response API
     * mana pun yang lupa meng-exclude kolom ini secara eksplisit.
     *
     * @var list<string>
     */
    protected $hidden = [
        'encrypted_value',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'employee_benefit_participation_id' => 'string',
        'benefit_program_id' => 'string',
        'issued_at' => 'date',
        'expires_at' => 'date',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<EmployeeBenefitParticipation, $this>
     */
    public function participation(): BelongsTo
    {
        return $this->belongsTo(
            EmployeeBenefitParticipation::class,
            'employee_benefit_participation_id',
        );
    }

    /**
     * @return BelongsTo<BenefitProgram, $this>
     */
    public function benefitProgram(): BelongsTo
    {
        return $this->belongsTo(
            BenefitProgram::class,
            'benefit_program_id',
        );
    }
}
