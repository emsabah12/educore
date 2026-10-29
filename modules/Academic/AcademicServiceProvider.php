<?php

namespace Modules\Academic;

use Illuminate\Console\Scheduling\Schedule;
use Inertia\Inertia;
use Modules\Academic\Application\Attendance\AttendanceModeSetting;
use Modules\Academic\Application\Audit\AcademicAuditAction;
use Modules\Academic\Application\Authorization\AcademicAccess;
use Modules\Academic\Application\Calendar\AcademicSchoolWeeklyOff;
use Modules\Academic\Application\Calendar\CalendarAcademicYears;
use Modules\Academic\Application\Calendar\CalendarSetting;
use Modules\Academic\Application\Classroom\AcademicTeachingSchedule;
use Modules\Academic\Application\Classroom\MyClasses;
use Modules\Academic\Application\Grading\GradingSetting;
use Modules\Academic\Application\Guardian\MyChildren;
use Modules\Academic\Application\Student\AcademicEnrolledStudents;
use Modules\Academic\Application\Teaching\AcademicTeachingLoad;
use Modules\Academic\Application\Teaching\DutyRoster;
use Modules\Academic\Application\Teaching\SubstituteTasks;
use Modules\Academic\Console\PruneStudentImportsCommand;
use Modules\Academic\Contracts\AcademicYears;
use Modules\Academic\Contracts\EnrolledStudents;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Authorization\AccessCatalog;
use Modules\Core\Application\Settings\SettingRegistry;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Modules\Core\Support\ModuleServiceProvider;
use Modules\HR\Contracts\SchoolWeeklyOff;
use Modules\HR\Contracts\TeachingLoad;
use Modules\HR\Contracts\TeachingSchedule;

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

    public function register(): void
    {
        // Jam mengajar guru untuk presensi pegawai HR (PRD-001 §14.1): HR memakai kontraknya,
        // Academic mengisinya (menggantikan implementasi kosong bawaan HR).
        $this->app->singleton(TeachingSchedule::class, AcademicTeachingSchedule::class);
        // Beban mengajar untuk honor JP (PRD-001 §16.2).
        $this->app->singleton(TeachingLoad::class, AcademicTeachingLoad::class);
        // Libur mingguan kalender akademik untuk perbandingan di Pengaturan cuti & presensi (OD-HR-07).
        $this->app->singleton(SchoolWeeklyOff::class, AcademicSchoolWeeklyOff::class);
        // Kontrak publik Academic untuk modul lain (Modules\Academic\Contracts), mis. Finance (PRD-003 §7).
        $this->app->singleton(AcademicYears::class, CalendarAcademicYears::class);
        $this->app->singleton(EnrolledStudents::class, AcademicEnrolledStudents::class);
    }

    public function boot(): void
    {
        parent::boot();

        AcademicAccess::register($this->app->make(AccessCatalog::class));
        AuditAction::registerLabels(AcademicAuditAction::labels());

        // Kalender yang dipakai lembaga: aturan berjenjang Core (PRD-002 §4.4).
        $this->app->make(SettingRegistry::class)
            ->define(CalendarSetting::definition())
            // Mode presensi per lembaga (AC-4c).
            ->define(AttendanceModeSetting::definition())
            // Aturan penilaian per lembaga (AC-5a).
            ->define(GradingSetting::definition());

        // Petunjuk menu "Kelas saya" (AC-3d): dievaluasi saat halaman dirender, sesudah `work.context`.
        // Petunjuk menu: Kelas saya (AC-3d) & Presensi mengajar bagi guru piket hari ini (AC-6a).
        Inertia::share('academic', fn (): array => ['myClasses' => $this->hasMyClasses(), 'teachingDuty' => $this->isOnDutyToday(), 'isGuardian' => $this->isGuardian()]);

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
    private function isOnDutyToday(): bool
    {
        $context = $this->app->make(TenantContext::class)->get();

        if ($context === null) {
            return false;
        }

        $employeeId = $this->app->make(MyClasses::class)->employeeId($context->tenantId, $context->membershipId);

        return $employeeId !== null
            && $this->app->make(DutyRoster::class)->dutyOrganizations($context->tenantId, $employeeId, BusinessClock::todayString()) !== [];
    }

    /**
     * Pengguna yang sedang login tercatat sebagai orang tua/wali siswa (menu Anak saya, AC-7a).
     */
    private function isGuardian(): bool
    {
        $context = $this->app->make(TenantContext::class)->get();

        return $context !== null && $this->app->make(MyChildren::class)->isGuardian($context->tenantId, $context->membershipId);
    }

    private function hasMyClasses(): bool
    {
        $context = $this->app->make(TenantContext::class)->get();

        if ($context === null) {
            return false;
        }

        $myClasses = $this->app->make(MyClasses::class);
        $employeeId = $myClasses->employeeId($context->tenantId, $context->membershipId);

        // Guru pengganti yang tidak memegang kelas tetap mendapat menu untuk melihat tugasnya (AC-6b-2).
        return $employeeId !== null && ($myClasses->classes($context->tenantId, $employeeId) !== []
            || $this->app->make(SubstituteTasks::class)->hasAny($context->tenantId, $employeeId));
    }
}
