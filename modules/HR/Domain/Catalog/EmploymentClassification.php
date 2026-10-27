<?php

namespace Modules\HR\Domain\Catalog;

/**
 * Status kepegawaian yayasan, mis. GTY, GTT, PTY, PTT (PRD-001 §4.3, [LAMA] BR-004).
 *
 * @property bool $receives_teaching_pay menerima honor per JP (PRD-001 §16.2, HR-5b)
 */
class EmploymentClassification extends CatalogItem
{
    protected $table = 'hr_employment_classifications';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'receives_teaching_pay',
        'is_active',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'receives_teaching_pay' => false,
        'is_active' => true,
    ];

    public function kind(): CatalogKind
    {
        return CatalogKind::Classification;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'receives_teaching_pay' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
