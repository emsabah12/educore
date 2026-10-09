<?php

namespace Modules\HR;

use Modules\Core\Support\ModuleServiceProvider;

/**
 * Modul HR (kepegawaian). Spesifikasi domain mengacu ke HR-001 s.d. HR-016
 * di docs-legacy/ sampai PRD HR versi baru disusun.
 *
 * Aturan dependency: HR → Core.
 */
class HRServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }
}
