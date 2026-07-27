<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class RequireGlobalSuperadmin
{
    /**
     * Memastikan request hanya dapat dilanjutkan oleh user
     * yang memiliki privilege global superadmin.
     *
     * Sumber kebenaran authorization global:
     *
     * users.is_superadmin
     *
     * Middleware ini TIDAK menggunakan:
     *
     * - memberships.role
     * - authenticated_role
     * - role dari token
     *
     * karena role membership merupakan authorization kontekstual tenant,
     * sedangkan superadmin merupakan privilege global.
     *
     * @param Closure(Request): Response $next
     */
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $userId = $this->resolveAuthenticatedUserId($request);

        if ($userId === null) {
            return $this->forbiddenResponse();
        }

        if (! $this->isGlobalSuperadmin($userId)) {
            Log::warning(
                'Global superadmin authorization denied.',
                [
                    'user_id' => $userId,
                    'path' => $request->path(),
                    'method' => $request->method(),
                ]
            );

            return $this->forbiddenResponse();
        }

        return $next($request);
    }

    /**
     * Mengambil authenticated user ID dari canonical request context.
     *
     * @return string|null
     */
    private function resolveAuthenticatedUserId(
        Request $request
    ): ?string {
        $userId = $request->attributes->get(
            'authenticated_user_id'
        );

        if (! is_string($userId)) {
            return null;
        }

        $userId = trim($userId);

        return $userId !== ''
            ? $userId
            : null;
    }

    /**
     * Memverifikasi privilege global superadmin berdasarkan
     * source of truth users.is_superadmin.
     *
     * Query hanya mengambil satu scalar boolean sehingga
     * tidak mengambil data user yang tidak diperlukan.
     */
    private function isGlobalSuperadmin(
        string $userId
    ): bool {
        return DB::table('users')
            ->where('id', $userId)
            ->where('status', 'ACTIVE')
            ->where('is_superadmin', true)
            ->exists();
    }

    /**
     * Response generik untuk authorization failure.
     *
     * Detail internal sengaja tidak dibocorkan kepada client.
     */
    private function forbiddenResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Forbidden. This action requires global superadmin privileges.',
        ], Response::HTTP_FORBIDDEN);
    }
}
