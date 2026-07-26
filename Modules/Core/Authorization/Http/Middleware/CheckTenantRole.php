<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class CheckTenantRole
{
    /**
     * Menangani intersepsi HTTP request untuk memvalidasi Role kontekstual user di dalam Tenant.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $role Nama role yang diizinkan (misal: 'admin')
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        // 1. Defend: Pastikan user sudah terotentikasi di platform
        $user = Auth::user();
        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated access path.'
            ], Response::HTTP_UNAUTHORIZED);
        }

        // 2. Platform Owner Bypass: Superadmin global memiliki hak akses absolut di seluruh rute
        if ($user->is_superadmin) {
            return $next($request);
        }

        // 3. Ambil context membership_id yang sedang aktif
        // Context ini idealnya disuntikkan ke parameter request/session oleh TenantResolver Middleware sebelumnya
        $membershipId = $request->route('membership_id')
            ?? $request->header('X-Membership-ID')
            ?? session('active_membership_id');

        if (!$membershipId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Bad Request: Missing tenant membership context attributes.'
            ], Response::HTTP_BAD_REQUEST);
        }

        // 4. Lakukan pengecekan hak akses kontekstual menggunakan Trait
        if (!$user->hasRoleInMembership($role, (string) $membershipId)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized: Your role does not possess the required clearance level for this tenant domain.'
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
