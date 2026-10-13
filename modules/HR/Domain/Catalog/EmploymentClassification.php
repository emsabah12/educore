<?php

namespace Modules\HR\Domain\Catalog;

/**
 * Status kepegawaian yayasan, mis. GTY, GTT, PTY, PTT (PRD-001 §4.3, [LAMA] BR-004).
 */
class EmploymentClassification extends CatalogItem
{
    protected $table = 'hr_employment_classifications';

    public function kind(): CatalogKind
    {
        return CatalogKind::Classification;
    }
}
