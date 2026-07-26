<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Middleware\InjectTenantContext;
use Modules\HR\Http\Controllers\Api\v1\EmployeeManagementController;

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
    )->name('api.v1.hr.employees.index');

    Route::post(
        '/v1/hr/employees',
        [EmployeeManagementController::class, 'store']
    )->name('api.v1.hr.employees.store');
});
