<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\Core\Identity\Models\User;
use Symfony\Component\HttpFoundation\Response;

final class RequireGlobalSuperadmin
{
    public function __construct(
        private readonly AuthFactory $auth,
    ) {}

    /**
     * Memastikan request hanya dilanjutkan oleh canonical User
     * yang memiliki privilege global superadmin.
     *
     * Canonical source:
     *
     * users.is_superadmin
     *
     * Middleware tidak menggunakan Membership role,
     * tenant role, request role, atau token role claim.
     *
     * @param Closure(Request): Response $next
     */
    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        $user = $this->resolveAuthenticatedUser(
            $request,
        );

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

        $contextUserId = trim(
            $contextUserId,
        );

        if ($contextUserId === '') {
            return null;
        }

        $guardUserId = (string) $user
            ->getAuthIdentifier();

        if (
            ! hash_equals(
                $guardUserId,
                $contextUserId,
            )
        ) {
            return null;
        }

        return $user;
    }

    private function isGlobalSuperadmin(
        User $user,
    ): bool {
        return strtoupper(
            (string) $user->getAttribute(
                'status',
            ),
        ) === 'ACTIVE'
            && (bool) $user->getAttribute(
                'is_superadmin',
            );
    }

    private function forbiddenResponse(): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'AUTHORIZATION_DENIED',
            message: 'You are not allowed to perform this operation.',
            status: Response::HTTP_FORBIDDEN,
        );
    }
}
