<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Middleware\InjectTenantContext;
use Modules\Core\Organization\Http\Middleware\InjectOrganizationalContext;
use Modules\HR\Http\Controllers\Api\v1\EmployeeManagementController;
use Modules\HR\Http\Controllers\Api\v1\EmploymentManagementController;
use Modules\HR\Http\Controllers\Api\v1\EmploymentPlacementController;
use Modules\HR\Http\Controllers\Api\v1\EmploymentPositionAssignmentController;
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

Route::middleware([
    InjectTenantContext::class,
    InjectOrganizationalContext::class,
])->prefix('v1/hr/workspace')->group(function (): void {

    // HR-017 §2 — Workspace Employee Listing (resolves HR-013 §33).
    Route::get(
        '/employees',
        [EmployeeManagementController::class, 'indexWorkspace']
    )
        ->middleware('organizational.permission:hr.employees.view')
        ->name('api.v1.hr.workspace.employees.index');

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

    // HR-017 §2 — Workspace Employee Listing (resolves HR-013 §33).
    Route::get(
        '/employees',
        [EmployeeManagementController::class, 'indexWorkspace']
    )
        ->middleware('organizational.permission:hr.employees.view')
        ->name('api.v1.hr.workspace.employees.index');

    // HR-017 §3 — Workspace Employee Creation (resolves HR-013 §35).
    // Permission DIPAKAI ULANG (hr.employees.create) — bukan permission
    // baru — digrant lewat organizational_assignment_roles.
    Route::post(
        '/employees',
        [WorkspaceEmployeeProvisioningController::class, 'store']
    )
        ->middleware('organizational.permission:hr.employees.create')
        ->name('api.v1.hr.workspace.employees.store');

    Route::post(
        '/employees/{employeeId}/employments',
        [EmploymentManagementController::class, 'store']
    );
});
