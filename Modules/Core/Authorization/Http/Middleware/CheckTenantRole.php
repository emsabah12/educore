<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Authorization\Contracts\AuthorizationServiceInterface;
use Modules\Core\Authorization\Exceptions\MembershipContextResolutionException;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Symfony\Component\HttpFoundation\Response;

final class CheckTenantRole
{
    public function __construct(
        private readonly AuthorizationServiceInterface $authorizationService,
    ) {}

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(
        Request $request,
        Closure $next,
        string $role,
    ): Response {
        $user = Auth::user();

        if ($user === null) {
            return $this->unauthenticatedResponse();
        }

        /*
         * Global superadmin bypasses tenant-scoped role checks.
         *
         * Domain-specific invariants remain the responsibility
         * of downstream application services.
         */
        if ((bool) $user->is_superadmin) {
            return $next($request);
        }

        try {
            $hasRequiredRole = $this->authorizationService
                ->hasRole(
                    $role,
                );
        } catch (
            MembershipContextResolutionException $exception
        ) {
            report($exception);

            return $this->forbiddenResponse();
        }

        if (! $hasRequiredRole) {
            return $this->forbiddenResponse();
        }

        return $next($request);
    }

    private function unauthenticatedResponse(): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'AUTHENTICATION_REQUIRED',
            message: 'Unauthenticated. Invalid or missing identity context.',
            status: Response::HTTP_UNAUTHORIZED,
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
