<?php

namespace Modules\Academic;

use Modules\Core\Support\ModuleServiceProvider;

/**
 * Modul Academic (siswa/santri, kelas, nilai). PRD belum ada.
 *
 * Aturan dependency: Academic → Core, dan → HR hanya lewat kontrak publik
 * (Modules\HR\Contracts, Modules\HR\DTO).
 */
class AcademicServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }
}
