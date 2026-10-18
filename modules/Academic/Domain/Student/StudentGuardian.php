<?php

namespace Modules\Academic\Domain\Student;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Core\Domain\Tenancy\BelongsToTenant;
use Modules\Core\Domain\Tenancy\Membership;

/**
 * Hubungan siswa ↔ orang tua/wali (PRD-002 §4.5). Orang tua/wali adalah anggota yayasan biasa
 * (Person + Membership) tanpa akun login dan tanpa hak akses apa pun.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $student_id
 * @property string $guardian_membership_id
 * @property GuardianRelation $relation
 * @property bool $is_primary_contact
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Membership $guardian
 */
class StudentGuardian extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'ac_student_guardians';

    protected $fillable = [
        'tenant_id',
        'student_id',
        'guardian_membership_id',
        'relation',
        'is_primary_contact',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_primary_contact' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'relation' => GuardianRelation::class,
            'is_primary_contact' => 'boolean',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'guardian_membership_id');
    }
}
