<?php

declare(strict_types=1);

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireGlobalSuperadmin
{
    /**
     * Menangani interseptor pemfilteran hak akses berbasis peran global superadmin.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Ekstrak data klaim konteks otentikasi yang telah disuntikkan oleh InjectTenantContext
        // Atribut ini didapatkan dari payload biner JWT / Session claims yang sah.
        $userRole = $request->attributes->get('authenticated_role');
        $userId = $request->attributes->get('authenticated_user_id');

        // 2. Defensive Guard: Jika data otentikasi kosong atau peran bukan SUPERADMIN, tolak akses seketika.
        // Kita menggunakan kode standar HTTP 403 Forbidden untuk menegaskan penolakan otorisasi.
        if (! $userId || $userRole !== 'SUPERADMIN') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Forbidden. This action requires global superadmin privileges.',
            ], 403);
        }

        // 3. Lanjutkan request ke lapisan internal berikutnya (Controller/Middleware lain)
        return $next($request);
    }
}
