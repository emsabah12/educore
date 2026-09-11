<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Middleware\InjectTenantContext;
use Modules\Auth\Http\Middleware\InjectTransportAwareTenantContext;
use Modules\Auth\Http\Middleware\UseBrowserSessionForCanonicalApi;
use Modules\Core\Organization\Http\Middleware\InjectOrganizationalContext;
use Modules\HR\Http\Controllers\Api\v1\BenefitIdentifierController;
use Modules\HR\Http\Controllers\Api\v1\BenefitProgramController;
use Modules\HR\Http\Controllers\Api\v1\CompensationAdjustmentController;
use Modules\HR\Http\Controllers\Api\v1\CompensationAssignmentController;
use Modules\HR\Http\Controllers\Api\v1\CompensationComponentController;
use Modules\HR\Http\Controllers\Api\v1\EmployeeBenefitParticipationController;
use Modules\HR\Http\Controllers\Api\v1\EmployeeManagementController;
use Modules\HR\Http\Controllers\Api\v1\EmploymentCatalogController;
use Modules\HR\Http\Controllers\Api\v1\EmploymentManagementController;
use Modules\HR\Http\Controllers\Api\v1\EmploymentPlacementController;
use Modules\HR\Http\Controllers\Api\v1\EmploymentPositionAssignmentController;
use Modules\HR\Http\Controllers\Api\v1\HireConversionController;
use Modules\HR\Http\Controllers\Api\v1\LeaveApprovalController;
use Modules\HR\Http\Controllers\Api\v1\LeaveApprovalPolicyController;
use Modules\HR\Http\Controllers\Api\v1\LeaveEntitlementController;
use Modules\HR\Http\Controllers\Api\v1\LeaveEntitlementPolicyController;
use Modules\HR\Http\Controllers\Api\v1\LeaveRequestController;
use Modules\HR\Http\Controllers\Api\v1\LeaveSelfServiceController;
use Modules\HR\Http\Controllers\Api\v1\LeaveTypeController;
use Modules\HR\Http\Controllers\Api\v1\OnboardingCaseController;
use Modules\HR\Http\Controllers\Api\v1\OnboardingTemplateController;
use Modules\HR\Http\Controllers\Api\v1\RecruitmentApplicationController;
use Modules\HR\Http\Controllers\Api\v1\RecruitmentCandidateController;
use Modules\HR\Http\Controllers\Api\v1\RecruitmentVacancyController;
use Modules\HR\Http\Controllers\Api\v1\WorkspaceEmployeeProvisioningController;



/*
|--------------------------------------------------------------------------
| HR Module API Routes
|--------------------------------------------------------------------------
*/

Route::middleware([
    InjectTenantContext::class,
])->group(function (): void {

    Route::get(
        '/v1/hr/employees',
        [EmployeeManagementController::class, 'index']
    )
        ->middleware('tenant.permission:hr.employees.view')
        ->name('api.v1.hr.employees.index');

    Route::post(
        '/v1/hr/employees',
        [EmployeeManagementController::class, 'store']
    )
        ->middleware('tenant.permission:hr.employees.create')
        ->name('api.v1.hr.employees.store');

    // HR-002 §10.2 — Employment Lifecycle.
    Route::get(
        '/v1/hr/employees/{employeeId}/employments',
        [EmploymentManagementController::class, 'index']
    )
        ->middleware('tenant.permission:hr.employments.view')
        ->name('api.v1.hr.employees.employments.index');

    Route::post(
        '/v1/hr/employees/{employeeId}/employments',
        [EmploymentManagementController::class, 'store']
    )
        ->middleware('tenant.permission:hr.employments.manage')
        ->name('api.v1.hr.employees.employments.store');

    Route::post(
        '/v1/hr/employments/{employmentId}/activate',
        [EmploymentManagementController::class, 'activate']
    )
        ->middleware('tenant.permission:hr.employments.manage')
        ->name('api.v1.hr.employments.activate');

    Route::post(
        '/v1/hr/employments/{employmentId}/cancel',
        [EmploymentManagementController::class, 'cancel']
    )
        ->middleware('tenant.permission:hr.employments.manage')
        ->name('api.v1.hr.employments.cancel');

    // HR-002 §9.4 — End Employment. Permission TERPISAH dari
    // hr.employments.manage (higher-impact operation, HR-013-BR-002).
    Route::post(
        '/v1/hr/employments/{employmentId}/end',
        [EmploymentManagementController::class, 'end']
    )
        ->middleware('tenant.permission:hr.employments.end')
        ->name('api.v1.hr.employments.end');

    // HR-002 §5.6 / §9.2 — Employment Placement.
    Route::get(
        '/v1/hr/employments/{employmentId}/placements',
        [EmploymentPlacementController::class, 'index']
    )
        ->middleware('tenant.permission:hr.employments.view')
        ->name('api.v1.hr.employments.placements.index');

    Route::post(
        '/v1/hr/employments/{employmentId}/placements',
        [EmploymentPlacementController::class, 'store']
    )
        ->middleware('tenant.permission:hr.employments.manage')
        ->name('api.v1.hr.employments.placements.store');

    // HR-002 §5.7 / §9.3 — Employment Position Assignment.
    Route::get(
        '/v1/hr/employments/{employmentId}/position-assignments',
        [EmploymentPositionAssignmentController::class, 'index']
    )
        ->middleware('tenant.permission:hr.employments.view')
        ->name('api.v1.hr.employments.position-assignments.index');

    Route::post(
        '/v1/hr/employments/{employmentId}/position-assignments',
        [EmploymentPositionAssignmentController::class, 'store']
    )
        ->middleware('tenant.permission:hr.employments.manage')
        ->name('api.v1.hr.employments.position-assignments.store');

    // HR-006 §7.3 — Compensation Assignment lifecycle.
    Route::get(
        '/v1/hr/employments/{employmentId}/compensation-assignments',
        [CompensationAssignmentController::class, 'index']
    )
        ->middleware('tenant.permission:hr.compensation.assignments.view')
        ->name('api.v1.hr.employments.compensation-assignments.index');

    Route::post(
        '/v1/hr/employments/{employmentId}/compensation-assignments',
        [CompensationAssignmentController::class, 'store']
    )
        ->middleware('tenant.permission:hr.compensation.assignments.manage')
        ->name('api.v1.hr.employments.compensation-assignments.store');

    // approve/correct SENGAJA memakai permission terpisah (higher-impact
    // operation, mengubah/mengunci riwayat kompensasi APPROVED) —
    // konsisten dengan pola hr.recruitment.approve.
    Route::post(
        '/v1/hr/employments/{employmentId}/compensation-assignments/{assignmentId}/approve',
        [CompensationAssignmentController::class, 'approve']
    )
        ->middleware('tenant.permission:hr.compensation.assignments.approve')
        ->name('api.v1.hr.employments.compensation-assignments.approve');

    Route::post(
        '/v1/hr/employments/{employmentId}/compensation-assignments/{assignmentId}/end',
        [CompensationAssignmentController::class, 'end']
    )
        ->middleware('tenant.permission:hr.compensation.assignments.manage')
        ->name('api.v1.hr.employments.compensation-assignments.end');

    Route::post(
        '/v1/hr/employments/{employmentId}/compensation-assignments/{assignmentId}/correct',
        [CompensationAssignmentController::class, 'correct']
    )
        ->middleware('tenant.permission:hr.compensation.assignments.approve')
        ->name('api.v1.hr.employments.compensation-assignments.correct');

    // HR-006 §7.5 — Benefit Program catalog.
    Route::get(
        '/v1/hr/benefits/programs',
        [BenefitProgramController::class, 'index']
    )
        ->middleware('tenant.permission:hr.benefit.programs.view')
        ->name('api.v1.hr.benefits.programs.index');

    Route::post(
        '/v1/hr/benefits/programs',
        [BenefitProgramController::class, 'store']
    )
        ->middleware('tenant.permission:hr.benefit.programs.manage')
        ->name('api.v1.hr.benefits.programs.store');

    // HR-006 §7.6 — Employee Benefit Participation lifecycle.
    Route::get(
        '/v1/hr/employments/{employmentId}/benefit-participations',
        [EmployeeBenefitParticipationController::class, 'index']
    )
        ->middleware('tenant.permission:hr.benefit.participations.view')
        ->name('api.v1.hr.employments.benefit-participations.index');

    Route::post(
        '/v1/hr/employments/{employmentId}/benefit-participations',
        [EmployeeBenefitParticipationController::class, 'store']
    )
        ->middleware('tenant.permission:hr.benefit.participations.manage')
        ->name('api.v1.hr.employments.benefit-participations.store');

    // enroll SENGAJA memakai permission terpisah (higher-impact
    // operation — sekaligus tindakan verifikasi administratif),
    // konsisten dengan pola hr.compensation.assignments.approve.
    Route::post(
        '/v1/hr/employments/{employmentId}/benefit-participations/{participationId}/enroll',
        [EmployeeBenefitParticipationController::class, 'enroll']
    )
        ->middleware('tenant.permission:hr.benefit.participations.enroll')
        ->name('api.v1.hr.employments.benefit-participations.enroll');

    Route::post(
        '/v1/hr/employments/{employmentId}/benefit-participations/{participationId}/suspend',
        [EmployeeBenefitParticipationController::class, 'suspend']
    )
        ->middleware('tenant.permission:hr.benefit.participations.manage')
        ->name('api.v1.hr.employments.benefit-participations.suspend');

    Route::post(
        '/v1/hr/employments/{employmentId}/benefit-participations/{participationId}/reinstate',
        [EmployeeBenefitParticipationController::class, 'reinstate']
    )
        ->middleware('tenant.permission:hr.benefit.participations.manage')
        ->name('api.v1.hr.employments.benefit-participations.reinstate');

    Route::post(
        '/v1/hr/employments/{employmentId}/benefit-participations/{participationId}/end',
        [EmployeeBenefitParticipationController::class, 'end']
    )
        ->middleware('tenant.permission:hr.benefit.participations.manage')
        ->name('api.v1.hr.employments.benefit-participations.end');

    // HR-006 §7.7 — Employee Benefit Identifier (nomor BPJS, dst.).
    // Nested langsung di bawah participationId (bukan employmentId)
    // karena repository tidak butuh employmentId sama sekali —
    // benefit_program_id diturunkan server-side dari participation
    // yang direferensikan, tidak pernah dari input client, jadi FK
    // komposit tidak pernah bisa "dipaksa" mismatch lewat endpoint ini.
    //
    // `index` (membaca value TERDEKRIPSI) digerbang permission
    // TERPISAH dari `store` (menulis) — membaca identifier mentah
    // secara operasional lebih sensitif daripada mendaftarkannya.
    Route::get(
        '/v1/hr/benefit-participations/{participationId}/identifiers',
        [BenefitIdentifierController::class, 'index']
    )
        ->middleware('tenant.permission:hr.benefit.identifiers.view')
        ->name('api.v1.hr.benefit-participations.identifiers.index');

    Route::post(
        '/v1/hr/benefit-participations/{participationId}/identifiers',
        [BenefitIdentifierController::class, 'store']
    )
        ->middleware('tenant.permission:hr.benefit.identifiers.manage')
        ->name('api.v1.hr.benefit-participations.identifiers.store');

    // HR-006 §7.8 — Compensation Adjustment lifecycle (maker-checker).
    Route::get(
        '/v1/hr/employments/{employmentId}/compensation-adjustments',
        [CompensationAdjustmentController::class, 'index']
    )
        ->middleware('tenant.permission:hr.compensation.adjustments.view')
        ->name('api.v1.hr.employments.compensation-adjustments.index');

    Route::post(
        '/v1/hr/employments/{employmentId}/compensation-adjustments',
        [CompensationAdjustmentController::class, 'store']
    )
        ->middleware('tenant.permission:hr.compensation.adjustments.manage')
        ->name('api.v1.hr.employments.compensation-adjustments.store');

    Route::post(
        '/v1/hr/employments/{employmentId}/compensation-adjustments/{adjustmentId}/submit',
        [CompensationAdjustmentController::class, 'submit']
    )
        ->middleware('tenant.permission:hr.compensation.adjustments.manage')
        ->name('api.v1.hr.employments.compensation-adjustments.submit');

    Route::post(
        '/v1/hr/employments/{employmentId}/compensation-adjustments/{adjustmentId}/cancel',
        [CompensationAdjustmentController::class, 'cancel']
    )
        ->middleware('tenant.permission:hr.compensation.adjustments.manage')
        ->name('api.v1.hr.employments.compensation-adjustments.cancel');

    // approve/reject SENGAJA memakai permission terpisah (higher-impact,
    // maker-checker — checker harus bisa didelegasikan terpisah dari
    // orang yang bisa membuat/submit adjustment).
    Route::post(
        '/v1/hr/employments/{employmentId}/compensation-adjustments/{adjustmentId}/approve',
        [CompensationAdjustmentController::class, 'approve']
    )
        ->middleware('tenant.permission:hr.compensation.adjustments.approve')
        ->name('api.v1.hr.employments.compensation-adjustments.approve');

    Route::post(
        '/v1/hr/employments/{employmentId}/compensation-adjustments/{adjustmentId}/reject',
        [CompensationAdjustmentController::class, 'reject']
    )
        ->middleware('tenant.permission:hr.compensation.adjustments.approve')
        ->name('api.v1.hr.employments.compensation-adjustments.reject');

    // HR-003 §7.1 / §8.1 — Recruitment Vacancy lifecycle.
    Route::get(
        '/v1/hr/recruitment/vacancies',
        [RecruitmentVacancyController::class, 'index']
    )
        ->middleware('tenant.permission:hr.recruitment.view')
        ->name('api.v1.hr.recruitment.vacancies.index');

    Route::post(
        '/v1/hr/recruitment/vacancies',
        [RecruitmentVacancyController::class, 'store']
    )
        ->middleware('tenant.permission:hr.recruitment.manage')
        ->name('api.v1.hr.recruitment.vacancies.store');

    Route::post(
        '/v1/hr/recruitment/vacancies/{vacancyId}/submit',
        [RecruitmentVacancyController::class, 'submit']
    )
        ->middleware('tenant.permission:hr.recruitment.manage')
        ->name('api.v1.hr.recruitment.vacancies.submit');

    // approve/reject SENGAJA memakai permission terpisah
    // (hr.recruitment.approve) — bukan hr.recruitment.manage — karena
    // ini higher-impact operation (§7.2: keputusan bisnis eksplisit).
    Route::post(
        '/v1/hr/recruitment/vacancies/{vacancyId}/approve',
        [RecruitmentVacancyController::class, 'approve']
    )
        ->middleware('tenant.permission:hr.recruitment.approve')
        ->name('api.v1.hr.recruitment.vacancies.approve');

    Route::post(
        '/v1/hr/recruitment/vacancies/{vacancyId}/reject',
        [RecruitmentVacancyController::class, 'reject']
    )
        ->middleware('tenant.permission:hr.recruitment.approve')
        ->name('api.v1.hr.recruitment.vacancies.reject');

    Route::post(
        '/v1/hr/recruitment/vacancies/{vacancyId}/open',
        [RecruitmentVacancyController::class, 'open']
    )
        ->middleware('tenant.permission:hr.recruitment.manage')
        ->name('api.v1.hr.recruitment.vacancies.open');

    Route::post(
        '/v1/hr/recruitment/vacancies/{vacancyId}/close',
        [RecruitmentVacancyController::class, 'close']
    )
        ->middleware('tenant.permission:hr.recruitment.manage')
        ->name('api.v1.hr.recruitment.vacancies.close');

    Route::post(
        '/v1/hr/recruitment/vacancies/{vacancyId}/cancel',
        [RecruitmentVacancyController::class, 'cancel']
    )
        ->middleware('tenant.permission:hr.recruitment.manage')
        ->name('api.v1.hr.recruitment.vacancies.cancel');

    // HR-003 §7.4 — Candidate.
    Route::get(
        '/v1/hr/recruitment/candidates',
        [RecruitmentCandidateController::class, 'index']
    )
        ->middleware('tenant.permission:hr.recruitment.view')
        ->name('api.v1.hr.recruitment.candidates.index');

    Route::post(
        '/v1/hr/recruitment/candidates',
        [RecruitmentCandidateController::class, 'store']
    )
        ->middleware('tenant.permission:hr.recruitment.manage')
        ->name('api.v1.hr.recruitment.candidates.store');

    // HR-003 §7.6 / §8.2 — Application (Candidate x Vacancy) lifecycle.
    Route::get(
        '/v1/hr/recruitment/vacancies/{vacancyId}/applications',
        [RecruitmentApplicationController::class, 'index']
    )
        ->middleware('tenant.permission:hr.recruitment.view')
        ->name('api.v1.hr.recruitment.vacancies.applications.index');

    Route::post(
        '/v1/hr/recruitment/vacancies/{vacancyId}/applications',
        [RecruitmentApplicationController::class, 'store']
    )
        ->middleware('tenant.permission:hr.recruitment.manage')
        ->name('api.v1.hr.recruitment.vacancies.applications.store');

    Route::post(
        '/v1/hr/recruitment/applications/{applicationId}/start-processing',
        [RecruitmentApplicationController::class, 'startProcessing']
    )
        ->middleware('tenant.permission:hr.recruitment.manage')
        ->name('api.v1.hr.recruitment.applications.start-processing');

    Route::post(
        '/v1/hr/recruitment/applications/{applicationId}/reject',
        [RecruitmentApplicationController::class, 'reject']
    )
        ->middleware('tenant.permission:hr.recruitment.manage')
        ->name('api.v1.hr.recruitment.applications.reject');

    Route::post(
        '/v1/hr/recruitment/applications/{applicationId}/withdraw',
        [RecruitmentApplicationController::class, 'withdraw']
    )
        ->middleware('tenant.permission:hr.recruitment.manage')
        ->name('api.v1.hr.recruitment.applications.withdraw');

    // approve-for-hiring SENGAJA memakai hr.recruitment.approve (bukan
    // .manage) — higher-impact operation, konsisten dengan pola
    // Vacancy approve/reject.
    Route::post(
        '/v1/hr/recruitment/applications/{applicationId}/approve-for-hiring',
        [RecruitmentApplicationController::class, 'approveForHiring']
    )
        ->middleware('tenant.permission:hr.recruitment.approve')
        ->name('api.v1.hr.recruitment.applications.approve-for-hiring');

    // HR-003 §7.10 — Onboarding Template.
    Route::get(
        '/v1/hr/onboarding/templates',
        [OnboardingTemplateController::class, 'index']
    )
        ->middleware('tenant.permission:hr.onboarding.view')
        ->name('api.v1.hr.onboarding.templates.index');

    Route::post(
        '/v1/hr/onboarding/templates',
        [OnboardingTemplateController::class, 'store']
    )
        ->middleware('tenant.permission:hr.onboarding.manage')
        ->name('api.v1.hr.onboarding.templates.store');

    // HR-003 §7.12 / §8.3 — Onboarding Case lifecycle.
    Route::post(
        '/v1/hr/recruitment/applications/{applicationId}/onboarding',
        [OnboardingCaseController::class, 'store']
    )
        ->middleware('tenant.permission:hr.onboarding.manage')
        ->name('api.v1.hr.onboarding.cases.store');

    Route::post(
        '/v1/hr/onboarding/cases/{caseId}/start',
        [OnboardingCaseController::class, 'start']
    )
        ->middleware('tenant.permission:hr.onboarding.manage')
        ->name('api.v1.hr.onboarding.cases.start');

    Route::post(
        '/v1/hr/onboarding/cases/{caseId}/cancel',
        [OnboardingCaseController::class, 'cancel']
    )
        ->middleware('tenant.permission:hr.onboarding.manage')
        ->name('api.v1.hr.onboarding.cases.cancel');

    Route::post(
        '/v1/hr/onboarding/tasks/{taskId}/complete',
        [OnboardingCaseController::class, 'completeTask']
    )
        ->middleware('tenant.permission:hr.onboarding.manage')
        ->name('api.v1.hr.onboarding.tasks.complete');

    // waive SENGAJA memakai hr.onboarding.activate (bukan .manage) —
    // "waived required task requires permission/audit" (§16).
    Route::post(
        '/v1/hr/onboarding/tasks/{taskId}/waive',
        [OnboardingCaseController::class, 'waiveTask']
    )
        ->middleware('tenant.permission:hr.onboarding.activate')
        ->name('api.v1.hr.onboarding.tasks.waive');

    // HR-003 §12 — Hiring Conversion Transaction (RM-HR-03 Fase E).
    // hr.recruitment.approve (bukan .manage) — higher-impact operation,
    // konsisten dengan pola approve-for-hiring.
    Route::post(
        '/v1/hr/recruitment/applications/{applicationId}/hire-conversion',
        [HireConversionController::class, 'store']
    )
        ->middleware('tenant.permission:hr.recruitment.approve')
        ->name('api.v1.hr.recruitment.applications.hire-conversion');
});

/*
|--------------------------------------------------------------------------
| HR Module API Routes — Organizationally-Scoped Workspace (RM-HR-02)
|--------------------------------------------------------------------------
|
| HR-002 §12.3 Mutation Scope: mutasi Employment/Placement/Position
| Assignment boleh dilakukan lewat workspace organisasi/unit SELAMA
| Employee target sudah visible di workspace tersebut (HR-013 §6).
| Route ini SENGAJA memakai ulang controller action yang sama persis
| dengan grup tenant-wide di atas — bedanya cuma middleware chain dan
| permission source-nya (organizational.permission, bukan
| tenant.permission). Controller sendiri yang mendeteksi OrganizationalContext
| aktif dan menegakkan resource-scope check (lihat ChecksHrResourceScope).
|
| "Workspace Employee Listing" (HR-013 §33) sekarang RESOLVED oleh HR-017
| §2 — lihat route GET /employees di bawah.
| "Workspace Employee Creation" (POST /employees tenant-baru dari
| workspace) MASIH ditunda — HR-013 §35 sudah RESOLVED secara desain di
| HR-017 §3, tapi implementasinya menyusul sebagai step terpisah.
|--------------------------------------------------------------------------
*/

/*
 * §Perbaikan: workspace/* di bawah ini dipanggil LANGSUNG oleh frontend
 * React (sesi browser berbasis cookie, TIDAK PERNAH menyimpan bearer
 * token — lihat ADR-030). `InjectTenantContext` yang lama HANYA baca
 * `$request->bearerToken()`, jadi tidak bisa dipakai di sini.
 *
 * `InjectTransportAwareTenantContext` adalah pengganti AMAN: kalau
 * request bawa cookie sesi browser, dia pakai jalur cookie; kalau
 * tidak (klien bearer-token lain, mis. mobile app nanti), dia
 * delegasikan PERSIS ke `InjectTenantContext` lama — perilaku klien
 * bearer yang sudah ada TIDAK BERUBAH SAMA SEKALI. Pola yang SAMA
 * PERSIS sudah dipakai & teruji di endpoint kanonik
 * `GET /core/authorization/capabilities`.
 *
 * Endpoint HR-004 LAIN di luar prefix `workspace` ini SENGAJA belum
 * diubah — ditangani satu per satu seiring halaman frontend-nya
 * dibangun, bukan sekaligus semua.
 */

/*
 * HR-002 §3 — read-only tenant catalog, dual-transport so the
 * browser-session frontend (e.g. the Tambah Employment dropdown)
 * can call it directly, same pattern as /core/tenant-roles.
 * Deliberately its OWN group rather than folded into the
 * organizational workspace group below — Employment Type is
 * tenant-wide, not scoped to a particular organizational context.
 */
Route::middleware([
    UseBrowserSessionForCanonicalApi::class,
    InjectTransportAwareTenantContext::class,
    'tenant.feature:hr_module',
    'tenant.permission:hr.employments.view',
])->prefix('v1/hr')->group(function (): void {
    Route::get(
        '/employment-types',
        [EmploymentCatalogController::class, 'indexEmploymentTypes']
    )->name('api.v1.hr.employment-types.index');
});

Route::middleware([
    UseBrowserSessionForCanonicalApi::class,
    InjectTransportAwareTenantContext::class,
    'tenant.feature:hr_module',
    InjectOrganizationalContext::class,
])->prefix('v1/hr/workspace')->group(function (): void {

    // HR-017 §2 — Workspace Employee Listing (resolves HR-013 §33).
    Route::get(
        '/employees',
        [EmployeeManagementController::class, 'indexWorkspace']
    )
        ->middleware('organizational.permission:hr.employees.view')
        ->name('api.v1.hr.workspace.employees.index');

    Route::get(
        '/employees/{employeeId}',
        [EmployeeManagementController::class, 'showWorkspace']
    )
        ->middleware('organizational.permission:hr.employees.view')
        ->name('api.v1.hr.workspace.employees.show');

    Route::post(
        '/employees/{employeeId}/employments',
        [EmploymentManagementController::class, 'store']
    )
        ->middleware('organizational.permission:hr.employments.manage')
        ->name('api.v1.hr.workspace.employees.employments.store');

    Route::post(
        '/employments/{employmentId}/activate',
        [EmploymentManagementController::class, 'activate']
    )
        ->middleware('organizational.permission:hr.employments.manage')
        ->name('api.v1.hr.workspace.employments.activate');

    Route::post(
        '/employments/{employmentId}/cancel',
        [EmploymentManagementController::class, 'cancel']
    )
        ->middleware('organizational.permission:hr.employments.manage')
        ->name('api.v1.hr.workspace.employments.cancel');

    Route::post(
        '/employments/{employmentId}/end',
        [EmploymentManagementController::class, 'end']
    )
        ->middleware('organizational.permission:hr.employments.end')
        ->name('api.v1.hr.workspace.employments.end');

    Route::post(
        '/employments/{employmentId}/placements',
        [EmploymentPlacementController::class, 'store']
    )
        ->middleware('organizational.permission:hr.employments.manage')
        ->name('api.v1.hr.workspace.employments.placements.store');

    Route::post(
        '/employments/{employmentId}/position-assignments',
        [EmploymentPositionAssignmentController::class, 'store']
    )
        ->middleware('organizational.permission:hr.employments.manage')
        ->name('api.v1.hr.workspace.employments.position-assignments.store');

    // HR-017 §3 — Workspace Employee Creation (resolves HR-013 §35).
    // Permission DIPAKAI ULANG (hr.employees.create) — bukan permission
    // baru — digrant lewat organizational_assignment_roles.
    Route::post(
        '/employees',
        [WorkspaceEmployeeProvisioningController::class, 'store']
    )
        ->middleware('organizational.permission:hr.employees.create')
        ->name('api.v1.hr.workspace.employees.store');
});

/*
|--------------------------------------------------------------------------
| HR-004 — Leave & Permit System — Admin Configuration Routes
|--------------------------------------------------------------------------
| §15.1 Leave Type, §15.2 Entitlement Policy. Permission tenant-wide
| (hr.leave.policy.*) — konfigurasi ini berlaku di seluruh tenant, bukan
| per-workspace, sehingga tetap di grup InjectTenantContext biasa
| (bukan grup workspace organizational).
*/
Route::middleware([
    InjectTenantContext::class,
])->prefix('v1/hr')->group(function (): void {
    Route::get(
        '/leave-types',
        [LeaveTypeController::class, 'index']
    )
        ->middleware('tenant.permission:hr.leave.policy.read')
        ->name('api.v1.hr.leave-types.index');

    Route::post(
        '/leave-types',
        [LeaveTypeController::class, 'store']
    )
        ->middleware('tenant.permission:hr.leave.policy.manage')
        ->name('api.v1.hr.leave-types.store');

    Route::get(
        '/leave-types/{leaveTypeId}',
        [LeaveTypeController::class, 'show']
    )
        ->middleware('tenant.permission:hr.leave.policy.read')
        ->name('api.v1.hr.leave-types.show');

    Route::patch(
        '/leave-types/{leaveTypeId}',
        [LeaveTypeController::class, 'update']
    )
        ->middleware('tenant.permission:hr.leave.policy.manage')
        ->name('api.v1.hr.leave-types.update');

    Route::post(
        '/leave-types/{leaveTypeId}/deactivate',
        [LeaveTypeController::class, 'deactivate']
    )
        ->middleware('tenant.permission:hr.leave.policy.manage')
        ->name('api.v1.hr.leave-types.deactivate');

    Route::get(
        '/leave-entitlement-policies',
        [LeaveEntitlementPolicyController::class, 'index']
    )
        ->middleware('tenant.permission:hr.leave.policy.read')
        ->name('api.v1.hr.leave-entitlement-policies.index');

    Route::post(
        '/leave-entitlement-policies',
        [LeaveEntitlementPolicyController::class, 'store']
    )
        ->middleware('tenant.permission:hr.leave.policy.manage')
        ->name('api.v1.hr.leave-entitlement-policies.store');

    Route::get(
        '/leave-entitlement-policies/{entitlementPolicyId}',
        [LeaveEntitlementPolicyController::class, 'show']
    )
        ->middleware('tenant.permission:hr.leave.policy.read')
        ->name('api.v1.hr.leave-entitlement-policies.show');

    Route::post(
        '/leave-entitlement-policies/{entitlementPolicyId}/deactivate',
        [LeaveEntitlementPolicyController::class, 'deactivate']
    )
        ->middleware('tenant.permission:hr.leave.policy.manage')
        ->name('api.v1.hr.leave-entitlement-policies.deactivate');

    // §15.3 Entitlements / Balance.
    Route::get(
        '/employees/{employeeId}/leave-balances',
        [LeaveEntitlementController::class, 'employeeBalances']
    )
        ->middleware('tenant.permission:hr.leave.balance.read')
        ->name('api.v1.hr.employees.leave-balances.index');

    Route::get(
        '/employments/{employmentId}/leave-entitlements',
        [LeaveEntitlementController::class, 'employmentEntitlements']
    )
        ->middleware('tenant.permission:hr.leave.balance.read')
        ->name('api.v1.hr.employments.leave-entitlements.index');

    Route::post(
        '/employments/{employmentId}/leave-entitlements/generate',
        [LeaveEntitlementController::class, 'generate']
    )
        ->middleware('tenant.permission:hr.leave.policy.manage')
        ->name('api.v1.hr.employments.leave-entitlements.generate');

    Route::post(
        '/leave-entitlements/{entitlementId}/adjustments',
        [LeaveEntitlementController::class, 'adjust']
    )
        ->middleware('tenant.permission:hr.leave.balance.adjust')
        ->name('api.v1.hr.leave-entitlements.adjustments.store');

    // §15.4 Approval Policy.
    Route::get(
        '/leave-approval-policies',
        [LeaveApprovalPolicyController::class, 'index']
    )
        ->middleware('tenant.permission:hr.leave.policy.read')
        ->name('api.v1.hr.leave-approval-policies.index');

    Route::post(
        '/leave-approval-policies',
        [LeaveApprovalPolicyController::class, 'store']
    )
        ->middleware('tenant.permission:hr.leave.policy.manage')
        ->name('api.v1.hr.leave-approval-policies.store');

    Route::get(
        '/leave-approval-policies/{approvalPolicyId}',
        [LeaveApprovalPolicyController::class, 'show']
    )
        ->middleware('tenant.permission:hr.leave.policy.read')
        ->name('api.v1.hr.leave-approval-policies.show');

    Route::post(
        '/leave-approval-policies/{approvalPolicyId}/deactivate',
        [LeaveApprovalPolicyController::class, 'deactivate']
    )
        ->middleware('tenant.permission:hr.leave.policy.manage')
        ->name('api.v1.hr.leave-approval-policies.deactivate');

    // §15.5 Leave Request.
    Route::get(
        '/leave-requests',
        [LeaveRequestController::class, 'index']
    )
        ->middleware('tenant.permission:hr.leave.read')
        ->name('api.v1.hr.leave-requests.index');

    Route::post(
        '/leave-requests',
        [LeaveRequestController::class, 'store']
    )
        ->middleware('tenant.permission:hr.leave.manage')
        ->name('api.v1.hr.leave-requests.store');

    Route::get(
        '/leave-requests/{leaveRequestId}',
        [LeaveRequestController::class, 'show']
    )
        ->middleware('tenant.permission:hr.leave.read')
        ->name('api.v1.hr.leave-requests.show');

    Route::patch(
        '/leave-requests/{leaveRequestId}',
        [LeaveRequestController::class, 'update']
    )
        ->middleware('tenant.permission:hr.leave.manage')
        ->name('api.v1.hr.leave-requests.update');

    Route::post(
        '/leave-requests/{leaveRequestId}/submit',
        [LeaveRequestController::class, 'submit']
    )
        ->middleware('tenant.permission:hr.leave.manage')
        ->name('api.v1.hr.leave-requests.submit');

    Route::post(
        '/leave-requests/{leaveRequestId}/withdraw',
        [LeaveRequestController::class, 'withdraw']
    )
        ->middleware('tenant.permission:hr.leave.manage')
        ->name('api.v1.hr.leave-requests.withdraw');

    Route::post(
        '/leave-requests/{leaveRequestId}/cancel',
        [LeaveRequestController::class, 'cancel']
    )
        ->middleware('tenant.permission:hr.leave.cancel')
        ->name('api.v1.hr.leave-requests.cancel');

    // §15.6 Approval queue / decision.
    Route::get(
        '/leave-approvals/pending',
        [LeaveApprovalController::class, 'pending']
    )
        ->middleware('tenant.permission:hr.leave.approve')
        ->name('api.v1.hr.leave-approvals.pending');

    Route::post(
        '/leave-requests/{leaveRequestId}/approve',
        [LeaveApprovalController::class, 'approve']
    )
        ->middleware('tenant.permission:hr.leave.approve')
        ->name('api.v1.hr.leave-requests.approve');

    Route::post(
        '/leave-requests/{leaveRequestId}/reject',
        [LeaveApprovalController::class, 'reject']
    )
        ->middleware('tenant.permission:hr.leave.approve')
        ->name('api.v1.hr.leave-requests.reject');

    // §15.7 Self-service. Permission hr.leave.self.* — LIHAT
    // HrAuthorizationCatalogSeeder: permission ini SENGAJA tidak
    // di-auto-grant ke hr-officer, karena secara konseptual milik
    // SETIAP Employee (via membership sendiri), bukan staf HR.
    Route::get(
        '/self/leave-balances',
        [LeaveSelfServiceController::class, 'balances']
    )
        ->middleware('tenant.permission:hr.leave.self.read')
        ->name('api.v1.hr.self.leave-balances.index');

    Route::get(
        '/self/leave-requests',
        [LeaveSelfServiceController::class, 'index']
    )
        ->middleware('tenant.permission:hr.leave.self.read')
        ->name('api.v1.hr.self.leave-requests.index');

    Route::post(
        '/self/leave-requests',
        [LeaveSelfServiceController::class, 'store']
    )
        ->middleware('tenant.permission:hr.leave.self.request')
        ->name('api.v1.hr.self.leave-requests.store');

    Route::get(
        '/self/leave-requests/{leaveRequestId}',
        [LeaveSelfServiceController::class, 'show']
    )
        ->middleware('tenant.permission:hr.leave.self.read')
        ->name('api.v1.hr.self.leave-requests.show');

    Route::post(
        '/self/leave-requests/{leaveRequestId}/submit',
        [LeaveSelfServiceController::class, 'submit']
    )
        ->middleware('tenant.permission:hr.leave.self.request')
        ->name('api.v1.hr.self.leave-requests.submit');

    Route::post(
        '/self/leave-requests/{leaveRequestId}/withdraw',
        [LeaveSelfServiceController::class, 'withdraw']
    )
        ->middleware('tenant.permission:hr.leave.self.request')
        ->name('api.v1.hr.self.leave-requests.withdraw');

    // HR-006 §7.2 — Compensation Component catalog.
    Route::get(
        '/compensation/components',
        [CompensationComponentController::class, 'index']
    )
        ->middleware('tenant.permission:hr.compensation.components.view')
        ->name('api.v1.hr.compensation.components.index');

    Route::post(
        '/compensation/components',
        [CompensationComponentController::class, 'store']
    )
        ->middleware('tenant.permission:hr.compensation.components.manage')
        ->name('api.v1.hr.compensation.components.store');
});
