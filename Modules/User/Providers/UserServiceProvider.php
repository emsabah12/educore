<?php

declare(strict_types=1);

namespace Modules\User\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\User\Application\Queries\UserMembershipQueryInterface;
use Modules\User\Application\Queries\UserWorkspaceQueryInterface;
use Modules\User\Infrastructure\Queries\EloquentUserMembershipQuery;
use Modules\User\Infrastructure\Queries\EloquentUserWorkspaceQuery;
use RuntimeException;

final class UserServiceProvider extends ServiceProvider
{
    /**
     * Daftarkan dependency milik modul User.
     */
    public function register(): void
    {
        $this->app->bind(
            UserMembershipQueryInterface::class,
            EloquentUserMembershipQuery::class,
        );

        $this->app->bind(
            UserWorkspaceQueryInterface::class,
            EloquentUserWorkspaceQuery::class,
        );
    }

    /**
     * Bootstrap komponen modul User.
     */
    public function boot(): void
    {
        $this->registerRoutes();
    }

    /**
     * Daftarkan route API milik modul User.
     */
    private function registerRoutes(): void
    {
        $this->registerApiRoutes();
        $this->registerBrowserRoutes();
    }

    private function registerApiRoutes(): void
    {
        $routeFile = base_path(
            'Modules/User/Routes/api.php',
        );

        if (! is_file($routeFile)) {
            throw new RuntimeException(
                sprintf(
                    'User module route file was not found: %s',
                    $routeFile,
                ),
            );
        }

        Route::prefix('api')
            ->middleware('api')
            ->group($routeFile);
    }

    private function registerBrowserRoutes(): void
    {
        $routeFile = base_path(
            'Modules/User/Routes/browser.php',
        );

        if (! is_file($routeFile)) {
            return;
        }

        Route::prefix('api')
            ->middleware('web')
            ->group($routeFile);
    }
}
