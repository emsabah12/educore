<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Modules\Core\Http\Middleware\EnsureAccountUsable;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            // AddLinkHeadersForPreloadedAssets sengaja TIDAK dipasang: header `Link` berisi semua chunk JS halaman
            // bisa melebihi buffer header nginx (fastcgi_buffer_size) → 502 saat halaman detail dimuat ulang.
            // Tag modulepreload sudah ada di HTML dari @vite, jadi header itu hanya duplikat (fix UI-2, 2026-10-31).
            // Keluarkan akun nonaktif & tahan akun bersandi sementara (PRD-000 §4.7).
            EnsureAccountUsable::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
