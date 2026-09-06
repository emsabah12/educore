<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Versi Blade/session dari `RequireGlobalSuperadmin` (yang menjaga API
 * bearer). Panel superadmin platform TIDAK PERNAH memakai token bearer —
 * dia session-based lewat guard `web` bawaan Laravel.
 */
final class EnsureUserIsSuperadmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        if ($user === null) {
            return redirect()->guest(route('platform.login'));
        }

        if (! (bool) $user->is_superadmin) {
            abort(403, 'Akses ditolak: halaman ini khusus Superadmin Platform.');
        }

        return $next($request);
    }
}
