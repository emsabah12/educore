<?php

namespace Modules\Academic\Domain\Subject;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Core\Contracts\OwnedByOrganization;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Tenancy\BelongsToTenant;

/**
 * Mata pelajaran di sebuah lembaga (PRD-002 §16 AC-3a). Lembaganya = node pemilik data untuk
 * otorisasi per pohon lembaga.
 *
 * Jangan membuat/mengubah langsung lewat model; pakai Modules\Academic\Application\Subject\ManageSubjects.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $organization_id
 * @property string $name
 * @property string|null $code
 * @property int|null $grade_min
 * @property int|null $grade_max
 * @property bool $is_active
 * @property list<string>|null $grading_aspects kode aspek penilaian yang dinilai (null = semua; PRD-002 §22 AC-8a)
 * @property string|null $group_id kelompok mapel (PRD-002 §22.4 AC-8b)
 * @property int|null $sort_order urutan di kelompoknya (kecil dulu; kosong = sesudah yang berurutan)
 * @property string $grade_type self::NUMBER atau self::LETTER
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Organization $organization
 */
class Subject extends Model implements OwnedByOrganization
{
    use BelongsToTenant;
    use HasUuids;

    /** Jenis nilai: angka 0–100 (komponen berbobot). */
    public const NUMBER = 'NUMBER';

    /** Jenis nilai: huruf dari skala predikat (kegiatan di luar kelas/ekstra, AC-8b). */
    public const LETTER = 'LETTER';

    protected $table = 'ac_subjects';

    protected $fillable = [
        'tenant_id',
        'organization_id',
        'name',
        'code',
        'grade_min',
        'grade_max',
        'grading_aspects',
        'group_id',
        'sort_order',
        'grade_type',
        'is_active',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'grade_type' => self::NUMBER,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'grade_min' => 'integer',
            'grade_max' => 'integer',
            'is_active' => 'boolean',
            'grading_aspects' => 'array',
            'sort_order' => 'integer',
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

    /**
     * Rentang tingkat memuat tingkat ini (kosong = semua tingkat).
     */
    public function coversGrade(int $grade): bool
    {
        return $this->grade_min === null || $this->grade_max === null
            || ($grade >= $this->grade_min && $grade <= $this->grade_max);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
