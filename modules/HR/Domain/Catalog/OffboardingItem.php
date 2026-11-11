<?php

namespace Modules\HR\Domain\Catalog;

/**
 * Item checklist offboarding (PRD-001 §18.2, HR-7b), mis. "Kembalikan laptop/perangkat". Kode `AKUN-LOGIN`
 * dicentang otomatis bila akun login pegawai sudah nonaktif atau memang tidak ada.
 */
class OffboardingItem extends CatalogItem
{
    protected $table = 'hr_offboarding_items';

    public function kind(): CatalogKind
    {
        return CatalogKind::OffboardingItem;
    }
}
