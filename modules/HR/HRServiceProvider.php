<?php

namespace Modules\HR;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Authorization\AccessCatalog;
use Modules\Core\Support\ModuleServiceProvider;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Employee\EloquentActiveEmployees;
use Modules\HR\Application\Employee\EloquentEmployeeLookup;
use Modules\HR\Console\PruneImportsCommand;
use Modules\HR\Contracts\ActiveEmployees;
use Modules\HR\Contracts\EmployeeLookup;

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

    public function register(): void
    {
        // Kontrak publik untuk modul lain (Modules\HR\Contracts).
        $this->app->singleton(EmployeeLookup::class, EloquentEmployeeLookup::class);
        $this->app->singleton(ActiveEmployees::class, EloquentActiveEmployees::class);
    }

    public function boot(): void
    {
        parent::boot();

        HrAccess::register($this->app->make(AccessCatalog::class));
        AuditAction::registerLabels(HrAuditAction::labels());

        if ($this->app->runningInConsole()) {
            $this->commands([PruneImportsCommand::class]);
        }

        // File impor pegawai sementara tidak boleh tertinggal lebih dari 24 jam (PRD-001 §6.2).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('hr:prune-imports')->hourly()->withoutOverlapping();
        });
    }
}
