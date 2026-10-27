<?php

namespace Modules\HR\Domain\Catalog;

/**
 * Jenis pegawai, mis. Tetap, Kontrak, Honorer (PRD-001 §4.3, [LAMA] OD-HR-001).
 *
 * @property bool $receives_teaching_pay menerima honor per JP (PRD-001 §16.2, HR-5b)
 */
class EmploymentType extends CatalogItem
{
    protected $table = 'hr_employment_types';

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
        return CatalogKind::EmploymentType;
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
