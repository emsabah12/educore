<?php

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InjectTenantContext
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenantUuid = null;
        $userUuid = null;

        // 1. Coba ekstraksi dari Authorization Header (Mobile Client)
        $bearerToken = $request->bearerToken();
        if ($bearerToken) {
            $parts = explode('.', $bearerToken);
            if (count($parts) === 3) {
                $payload = json_decode(base64_decode($parts[1]), true);
                $tenantUuid = $payload['tenant_uuid'] ?? null;
                $userUuid = $payload['sub'] ?? null;
            }
        }

        // 2. Jika tidak ada header, coba ekstraksi dari Cookie (Web Client)
        if (!$tenantUuid && $request->hasCookie('educore_session')) {
            // Catatan: Pada sistem nyata, di sini dilakukan pencarian token_hash ke tabel auth_sessions
            // Untuk fase MVP ini, kita mensimulasikan ekstraksi context dari session bridge
            $tenantUuid = $request->headers->get('X-Tenant-UUID'); // Simulasi fallback bridge via header
            $userUuid = $request->headers->get('X-User-UUID');
        }

        // 3. Batalkan request (Fail-Fast) jika konteks Multi-Tenancy tidak terpenuhi
        if (!$tenantUuid) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Multi-tenancy context missing or invalid client token.'
            ], 403);
        }

        // 4. Bind context ke dalam Service Container secara global agar aman dari kebocoran data
        app()->singleton('current_tenant_uuid', fn() => $tenantUuid);
        app()->singleton('current_user_uuid', fn() => $userUuid);

        // Sisipkan informasi ke dalam objek request internal agar mudah diakses di level controller
        $request->attributes->set('tenant_uuid', $tenantUuid);
        $request->attributes->set('user_uuid', $userUuid);

        return $next($request);
    }
}
