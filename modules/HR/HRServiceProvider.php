<?php

namespace Modules\HR;

use Illuminate\Console\Scheduling\Schedule;
use Inertia\Inertia;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Authorization\AccessCatalog;
use Modules\Core\Application\Settings\SettingRegistry;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\ModuleServiceProvider;
use Modules\HR\Application\Attendance\NoTeachingSchedule;
use Modules\HR\Application\Attendance\WorkHoursSetting;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Application\Authorization\HrAccess;
use Modules\HR\Application\Employee\EloquentActiveEmployees;
use Modules\HR\Application\Employee\EloquentEmployeeLookup;
use Modules\HR\Application\Leave\LeaveApprovalSetting;
use Modules\HR\Application\Leave\WeeklyOffSetting;
use Modules\HR\Console\PruneImportsCommand;
use Modules\HR\Contracts\ActiveEmployees;
use Modules\HR\Contracts\EmployeeLookup;
use Modules\HR\Contracts\TeachingSchedule;

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

        // Jadwal mengajar untuk presensi (PRD-001 §14.1): bawaan kosong; modul Academic menggantinya.
        $this->app->singletonIf(TeachingSchedule::class, NoTeachingSchedule::class);
    }

    public function boot(): void
    {
        parent::boot();

        HrAccess::register($this->app->make(AccessCatalog::class));
        AuditAction::registerLabels(HrAuditAction::labels());

        // Libur mingguan kalender kerja HR per jenjang/lembaga (PRD-001 §13.2).
        $this->app->make(SettingRegistry::class)
            ->define(WeeklyOffSetting::definition())
            // Alur persetujuan cuti per jenjang/lembaga (PRD-001 §13.4).
            ->define(LeaveApprovalSetting::definition())
            // Jam kerja staf per jenjang/lembaga (PRD-001 §14.1).
            ->define(WorkHoursSetting::definition());

        // Petunjuk menu "Cuti saya" & "Presensi saya" (HR-3b, HR-4a): akun tertaut ke data pegawai di yayasan aktif.
        Inertia::share('hr', fn (): array => ['isEmployee' => $this->isEmployee()]);

        if ($this->app->runningInConsole()) {
            $this->commands([PruneImportsCommand::class]);
        }

        // File impor pegawai sementara tidak boleh tertinggal lebih dari 24 jam (PRD-001 §6.2).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('hr:prune-imports')->hourly()->withoutOverlapping();
        });
    }

    /**
     * Pengguna yang sedang login punya data pegawai di yayasan kerja aktif.
     */
    private function isEmployee(): bool
    {
        $context = $this->app->make(TenantContext::class)->get();

        return $context !== null && $this->app->make(EmployeeLookup::class)->employeeIdForMembership($context->tenantId, $context->membershipId) !== null;
    }
}
