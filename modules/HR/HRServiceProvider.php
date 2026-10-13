<?php

namespace Modules\HR;

use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Authorization\AccessCatalog;
use Modules\Core\Support\ModuleServiceProvider;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Authorization\HrAccess;

/**
 * Modul HR (kepegawaian), spesifikasi: PRD-001.
 *
 * Aturan dependency: HR → Core. Provider ini wajib di-boot SETELAH CoreServiceProvider
 * (bootstrap/providers.php), karena role bawaan Core diperluas di sini.
 */
class HRServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    public function boot(): void
    {
        parent::boot();

        HrAccess::register($this->app->make(AccessCatalog::class));
        AuditAction::registerLabels(HrAuditAction::labels());
    }
}
