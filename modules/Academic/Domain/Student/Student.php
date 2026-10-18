<?php

namespace Modules\Academic\Domain\Student;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Core\Domain\Tenancy\BelongsToTenant;
use Modules\Core\Domain\Tenancy\Membership;

/**
 * Profil siswa di sebuah yayasan (PRD-002 §4.2). Satu per orang per yayasan; isinya hanya NISN.
 * Nama & data pribadi ada di Person (lewat membership); status siswa ada di pendaftaran per lembaga.
 *
 * Jangan membuat/mengubah langsung lewat model; pakai service di Modules\Academic\Application\Student.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $membership_id
 * @property string|null $nisn
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Membership $membership
 */
class Student extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'ac_students';

    protected $fillable = [
        'tenant_id',
        'membership_id',
        'nisn',
    ];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /**
     * @return HasMany<Enrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * @return HasMany<StudentGuardian, $this>
     */
    public function guardians(): HasMany
    {
        return $this->hasMany(StudentGuardian::class);
    }
}
