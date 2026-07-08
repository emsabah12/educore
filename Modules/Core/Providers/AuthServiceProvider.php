<?php

namespace Modules\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Http\Guards\TenantAwareUserProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Daftarkan service autentikasi apa pun ke container.
     */
    public function register(): void
    {
        // ...
    }

    /**
     * Bootstrapping driver autentikasi kustom aplikasi.
     */
    public function boot(): void
    {
        // Daftarkan kustom user provider bernama 'tenant-eloquent' ke dalam Auth manager Laravel
        Auth::provider('tenant-eloquent', function ($app, array $config) {
            // Mengembalikan instance kustom user provider dengan model yang dikonfigurasi di config/auth.php
            return new TenantAwareUserProvider($app['hash'], $config['model']);
        });
    }
}