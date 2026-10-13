<?php

namespace Modules\HR\Domain\Catalog;

/**
 * Jenis pegawai, mis. Tetap, Kontrak, Honorer (PRD-001 §4.3, [LAMA] OD-HR-001).
 */
class EmploymentType extends CatalogItem
{
    protected $table = 'hr_employment_types';

    public function kind(): CatalogKind
    {
        return CatalogKind::EmploymentType;
    }
}
