<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Authorization\Contracts\AuthorizationServiceInterface;
use Modules\Core\Authorization\Exceptions\MembershipContextResolutionException;
use Symfony\Component\HttpFoundation\Response;

final class CheckTenantPermission
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
        string $permission,
    ): Response {
        $user = Auth::user();

        if ($user === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated access path.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        /*
         * Global superadmin bypasses tenant authorization checks, exactly like
         * CheckTenantRole. Domain actor requirements remain the responsibility
         * of the downstream use case; this middleware never fabricates one.
         */
        if ((bool) $user->is_superadmin) {
            return $next($request);
        }

        try {
            $hasRequiredPermission = $this->authorizationService
                ->hasPermission($permission);
        } catch (MembershipContextResolutionException $exception) {
            report($exception);

            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized: required tenant permission is missing.',
            ], Response::HTTP_FORBIDDEN);
        }

        if (! $hasRequiredPermission) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized: required tenant permission is missing.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
