<?php

namespace Modules\Academic;

use Illuminate\Console\Scheduling\Schedule;
use Inertia\Inertia;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Application\Authorization\AcademicAccess;
use Modules\Academic\Application\Calendar\CalendarSetting;
use Modules\Academic\Application\Classroom\MyClasses;
use Modules\Academic\Console\PruneStudentImportsCommand;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Authorization\AccessCatalog;
use Modules\Core\Application\Settings\SettingRegistry;
use Modules\Core\Domain\Tenancy\TenantContext;
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

        // Petunjuk menu "Kelas saya" (AC-3d): dievaluasi saat halaman dirender, sesudah `work.context`.
        Inertia::share('academic', fn (): array => ['myClasses' => $this->hasMyClasses()]);

        if ($this->app->runningInConsole()) {
            $this->commands([PruneStudentImportsCommand::class]);
        }

        // File impor siswa sementara tidak boleh tertinggal lebih dari 24 jam (AC-1e).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('academic:prune-imports')->hourly()->withoutOverlapping();
        });
    }

    /**
     * Pengguna yang sedang login memegang kelas tahun berjalan (wali kelas/pengampu).
     */
    private function hasMyClasses(): bool
    {
        $context = $this->app->make(TenantContext::class)->get();

        if ($context === null) {
            return false;
        }

        $myClasses = $this->app->make(MyClasses::class);
        $employeeId = $myClasses->employeeId($context->tenantId, $context->membershipId);

        return $employeeId !== null && $myClasses->classes($context->tenantId, $employeeId) !== [];
    }
}
