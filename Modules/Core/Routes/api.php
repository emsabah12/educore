<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\Api\v1\HealthCheckController;

/*
|--------------------------------------------------------------------------
| Core Module API Routes
|--------------------------------------------------------------------------
|
| Core owns only routes that do not require implementation details from
| optional/dependent modules. Authenticated Core capabilities are composed
| by the Auth module, which depends on Core.
|
*/

Route::get(
    '/v1/core/health',
    HealthCheckController::class
)->name('api.core.health');
