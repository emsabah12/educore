<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Auth\Application\Services\AuthenticatedIdentityResolver;
use Symfony\Component\HttpFoundation\Response;

final class InjectAuthenticatedUser
{
    public function __construct(
        private readonly AuthenticatedIdentityResolver $identityResolver,
        private readonly AuthFactory $auth,
    ) {}

    /**
     * Membentuk canonical identity context untuk request saat ini.
     *
     * Middleware ini tidak membentuk tenant context, membership context,
     * role context, atau permission context.
     */
    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        $bearerToken = $request->bearerToken();

        if (! is_string($bearerToken)) {
            return $this->unauthenticatedResponse();
        }

        $identity = $this->identityResolver->resolve(
            $bearerToken,
        );

        if ($identity === null) {
            return $this->unauthenticatedResponse();
        }

        /*
         * Binding ini hanya berlaku pada lifecycle request saat ini.
         * Tidak membuat session atau persistent login.
         */
        $guard = $this->auth->guard();
        $guard->setUser($identity->user);

        $request->attributes->set(
            'authenticated_user_id',
            $identity->userId,
        );

        try {
            return $next($request);
        } finally {
            $guard->forgetUser();

            $request->attributes->remove(
                'authenticated_user_id',
            );
        }
    }

    private function unauthenticatedResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Unauthenticated. Invalid or missing identity context.',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
