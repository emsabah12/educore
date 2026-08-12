<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Middleware\InjectTenantContext;
use Modules\Academic\Http\Controllers\Api\v1\AcademicClassController;
use Modules\Academic\Http\Controllers\Api\v1\AcademicSubjectController;
use Modules\Academic\Http\Controllers\Api\v1\StudentManagementController;
use Modules\Academic\Http\Controllers\Api\v1\GuardianManagementController;
use Modules\Academic\Http\Controllers\Api\v1\GuardianStudentManagementController;
use Modules\Academic\Http\Controllers\Api\v1\AcademicPeriodController;
use Modules\Academic\Http\Controllers\Api\v1\BulkGradingController;

/*
|--------------------------------------------------------------------------
| Academic Module API Routes
|--------------------------------------------------------------------------
|
| Route prefix "api" diberikan oleh module route provider.
|
*/

/*
|--------------------------------------------------------------------------
| Tenant Scoped Academic Routes
|--------------------------------------------------------------------------
*/

Route::middleware([
    InjectTenantContext::class,
])->group(function (): void {

    /*
    |--------------------------------------------------------------------------
    | Academic Classes
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/v1/academic/classes',
        [AcademicClassController::class, 'index']
    )->name('api.v1.academic.classes.index');

    Route::post(
        '/v1/academic/classes',
        [AcademicClassController::class, 'store']
    )->name('api.v1.academic.classes.store');

    /*
    |--------------------------------------------------------------------------
    | Academic Subjects
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/v1/academic/subjects',
        [AcademicSubjectController::class, 'index']
    )->name('api.v1.academic.subjects.index');

    Route::post(
        '/v1/academic/subjects',
        [AcademicSubjectController::class, 'store']
    )->name('api.v1.academic.subjects.store');

    /*
    |--------------------------------------------------------------------------
    | Students
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/v1/academic/students',
        [StudentManagementController::class, 'index']
    )->name('api.v1.academic.students.index');

    Route::post(
        '/v1/academic/students',
        [StudentManagementController::class, 'store']
    )->name('api.v1.academic.students.store');

    /*
    |--------------------------------------------------------------------------
    | Guardians
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/v1/academic/guardians',
        [GuardianManagementController::class, 'index']
    )->name('api.v1.academic.guardians.index');

    Route::post(
        '/v1/academic/guardians',
        [GuardianManagementController::class, 'store']
    )->name('api.v1.academic.guardians.store');

    /*
    |--------------------------------------------------------------------------
    | Guardian - Student Associations
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/v1/academic/guardians/{guardianId}/students',
        [GuardianStudentManagementController::class, 'index']
    )->name('api.v1.academic.guardians.students.index');

    Route::post(
        '/v1/academic/guardians/associations',
        [GuardianStudentManagementController::class, 'store']
    )->name('api.v1.academic.guardians.associations.store');

    Route::delete(
        '/v1/academic/guardians/associations',
        [GuardianStudentManagementController::class, 'destroy']
    )->name('api.v1.academic.guardians.associations.destroy');

    /*
    |--------------------------------------------------------------------------
    | Academic Period
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/v1/academic/academic-years',
        [AcademicPeriodController::class, 'indexYears']
    )->name('api.v1.academic.years.index');

    Route::post(
        '/v1/academic/academic-years',
        [AcademicPeriodController::class, 'storeYear']
    )->name('api.v1.academic.years.store');

    Route::post(
        '/v1/academic/academic-years/{yearId}/semesters',
        [AcademicPeriodController::class, 'storeSemester']
    )->name('api.v1.academic.semesters.store');

    /*
    |--------------------------------------------------------------------------
    | Bulk Grading
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/v1/academic/grades/bulk',
        [BulkGradingController::class, 'storeBulk']
    )
        ->middleware('tenant.permission:academic.grades.write')
        ->name('api.v1.academic.grades.bulk');
});
