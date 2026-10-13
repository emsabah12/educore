<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Identity\UserStatus;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dipasang di grup middleware "web" (bootstrap/app.php), berlaku untuk semua halaman:
 *
 * 1. Akun yang dinonaktifkan admin langsung dikeluarkan pada request berikutnya, termasuk yang
 *    masuk lewat cookie "ingat saya" (PRD-000 §4.7, §6).
 * 2. Akun dengan kata sandi sementara hanya boleh membuka halaman ganti sandi dan keluar
 *    (PRD-000 §4.7 F5a).
 */
final class EnsureAccountUsable
{
    /** Route yang tetap boleh dibuka selama kata sandi sementara belum diganti. */
    private const ALLOWED_WHILE_TEMPORARY = ['password.force.edit', 'password.force.update', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if ($user->status !== UserStatus::Active) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return redirect()->route('login');
        }

        if ($user->must_change_password && ! $request->routeIs(...self::ALLOWED_WHILE_TEMPORARY)) {
            return redirect()->route('password.force.edit');
        }

        return $next($request);
    }
}
