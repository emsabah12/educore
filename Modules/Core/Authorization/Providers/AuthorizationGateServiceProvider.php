<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Authorization\Contracts\AccessCheckerInterface;

final class AuthorizationGateServiceProvider extends AuthServiceProvider
{
    public function boot(): void
    {
        $this->registerPermissionGate();
    }

    /**
     * Register dynamic permission gate.
     *
     * Every Gate ability will be delegated to the AccessChecker.
     */
    private function registerPermissionGate(): void
    {
        Gate::before(
            function (
                mixed $user,
                string $ability,
            ): ?bool {
                return app(
                    AccessCheckerInterface::class,
                )->can($ability);
            },
        );
    }
}
