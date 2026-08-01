<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Authorization\Contracts\AuthorizationServiceInterface;
use Modules\Core\Authorization\Exceptions\MembershipContextResolutionException;
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
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated access path.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if ((bool) $user->is_superadmin) {
            return $next($request);
        }

        try {
            $hasRequiredRole = $this->authorizationService->hasRole(
                $role,
            );
        } catch (MembershipContextResolutionException $exception) {
            report($exception);

            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized: Your role does not possess the required clearance level for this tenant domain.',
            ], Response::HTTP_FORBIDDEN);
        }

        if (! $hasRequiredRole) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized: Your role does not possess the required clearance level for this tenant domain.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
