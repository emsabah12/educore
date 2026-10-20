<?php

namespace Modules\Academic;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Application\Authorization\AcademicAccess;
use Modules\Academic\Application\Calendar\CalendarSetting;
use Modules\Academic\Console\PruneStudentImportsCommand;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Authorization\AccessCatalog;
use Modules\Core\Application\Settings\SettingRegistry;
use Modules\Core\Support\ModuleServiceProvider;

/**
 * Modul Academic (siswa/santri, pendaftaran, kalender akademik, kelas), spesifikasi: PRD-002.
 *
 * Aturan dependency: Academic → Core, dan → HR hanya lewat kontrak publik
 * (Modules\HR\Contracts, Modules\HR\DTO). Provider ini wajib di-boot SETELAH CoreServiceProvider
 * (bootstrap/providers.php), karena role bawaan Core diperluas di sini.
 */
class AcademicServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    public function boot(): void
    {
        parent::boot();

        AcademicAccess::register($this->app->make(AccessCatalog::class));
        AuditAction::registerLabels(AcademicAuditAction::labels());

        // Kalender yang dipakai lembaga: aturan berjenjang Core (PRD-002 §4.4).
        $this->app->make(SettingRegistry::class)->define(CalendarSetting::definition());

        if ($this->app->runningInConsole()) {
            $this->commands([PruneStudentImportsCommand::class]);
        }

        // File impor siswa sementara tidak boleh tertinggal lebih dari 24 jam (AC-1e).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('academic:prune-imports')->hourly()->withoutOverlapping();
        });
    }
}
