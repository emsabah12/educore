<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Core\Identity\Models\User;
use Symfony\Component\HttpFoundation\Response;

final class RequireGlobalSuperadmin
{
    public function __construct(
        private readonly AuthFactory $auth,
    ) {}

    /**
     * Memastikan request hanya dapat dilanjutkan oleh canonical user
     * yang memiliki privilege global superadmin.
     *
     * Sumber kebenaran authorization global:
     *
     * users.is_superadmin
     *
     * Middleware ini tidak menggunakan membership, tenant role,
     * authenticated role, ataupun role claim dari token.
     *
     * @param Closure(Request): Response $next
     */
    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        $user = $this->resolveAuthenticatedUser($request);

        if (
            $user === null
            || ! $this->isGlobalSuperadmin($user)
        ) {
            Log::warning(
                'Global superadmin authorization denied.',
                [
                    'user_id' => $user !== null
                        ? (string) $user->getAuthIdentifier()
                        : null,
                    'path' => $request->path(),
                    'method' => $request->method(),
                ],
            );

            return $this->forbiddenResponse();
        }

        return $next($request);
    }

    /**
     * Mengambil canonical authenticated user dari request-scoped guard.
     *
     * authenticated_user_id diverifikasi agar guard identity dan request
     * context tidak dapat menunjuk ke dua identity berbeda.
     */
    private function resolveAuthenticatedUser(
        Request $request,
    ): ?User {
        $user = $this->auth->guard()->user();

        if (! $user instanceof User) {
            return null;
        }

        $contextUserId = $request->attributes->get(
            'authenticated_user_id',
        );

        if (! is_string($contextUserId)) {
            return null;
        }

        $contextUserId = trim($contextUserId);

        if ($contextUserId === '') {
            return null;
        }

        $guardUserId = (string) $user->getAuthIdentifier();

        if (! hash_equals($guardUserId, $contextUserId)) {
            return null;
        }

        return $user;
    }

    /**
     * Memeriksa global privilege pada canonical User.
     *
     * Status ACTIVE diperiksa kembali sebagai defense-in-depth walaupun
     * identity resolver juga telah memvalidasi status user.
     */
    private function isGlobalSuperadmin(
        User $user,
    ): bool {
        return strtoupper(
            (string) $user->getAttribute('status'),
        ) === 'ACTIVE'
            && (bool) $user->getAttribute(
                'is_superadmin',
            );
    }

    /**
     * Response generik agar detail authorization internal
     * tidak dibocorkan kepada client.
     */
    private function forbiddenResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Forbidden. This action requires global superadmin privileges.',
        ], Response::HTTP_FORBIDDEN);
    }
}
