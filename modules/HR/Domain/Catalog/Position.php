<?php

namespace Modules\HR\Domain\Catalog;

/**
 * Jabatan (PRD-001 §4.3). Jabatan BUKAN hak akses: memasang jabatan "Kepala Sekolah"
 * tidak memberi permission apa pun ([LAMA] BR-006, INV-HR-003).
 */
class Position extends CatalogItem
{
    protected $table = 'hr_positions';

    public function kind(): CatalogKind
    {
        return CatalogKind::Position;
    }
}
