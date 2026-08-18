<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\Browser\v1\BrowserSwitchMembershipController;

Route::post(
    '/v1/browser/user/memberships/{membership_id}/switch',
    BrowserSwitchMembershipController::class,
)
    ->block(10, 10)
    ->name('api.v1.browser.user.memberships.switch');
