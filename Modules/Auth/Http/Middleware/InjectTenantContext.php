<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Identity\Models\User;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class InjectTenantContext
{
    public function __construct(
        private readonly TokenManagerInterface $tokenManager,
        private readonly AuthFactory $auth,
        private readonly TenantContextInterface $tenantContext,
    ) {}

    /**
     * Membangun canonical authentication dan tenant context
     * untuk lifecycle request saat ini.
     *
     * Alur:
     *
     * Bearer token
     *   ↓
     * Token validation
     *   ↓
     * Canonical Core User
     *   ↓
     * Laravel Auth Guard
     *   ↓
     * Active Tenant
     *   ↓
     * TenantContext
     */
    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        $bearerToken = $request->bearerToken();

        if (! is_string($bearerToken) || trim($bearerToken) === '') {
            return $this->contextErrorResponse();
        }

        try {
            $payload = $this->tokenManager->validateAndExtract(
                trim($bearerToken),
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->contextErrorResponse();
        }

        if (! is_array($payload)) {
            return $this->contextErrorResponse();
        }

        $userUuid = $this->extractStringClaim(
            $payload,
            'user_id',
        );

        $tenantUuid = $this->extractStringClaim(
            $payload,
            'tenant_id',
        );

        if ($userUuid === null || $tenantUuid === null) {
            return $this->contextErrorResponse();
        }

        /*
         * Canonical identity lookup.
         *
         * User wajib berasal dari Modules\Core\Identity\Models\User,
         * sesuai provider canonical pada config/auth.php.
         */
        $user = User::query()->find($userUuid);

        if ($user === null) {
            return $this->contextErrorResponse();
        }

        /*
         * Bind user ke Laravel authentication guard untuk request aktif.
         *
         * Ini bukan persistent session login. Binding hanya berlaku
         * pada lifecycle request aplikasi saat ini.
         */
        $this->auth->guard()->setUser($user);

        /*
         * Resolve dan validasi tenant sebelum mengaktifkan boundary.
         */
        $tenant = Tenant::query()->find($tenantUuid);

        if ($tenant === null || ! (bool) $tenant->is_active) {
            $this->auth->guard()->forgetUser();

            return $this->contextErrorResponse();
        }

        $this->tenantContext->setCurrentTenant($tenant);

        /*
         * Request attributes tetap menjadi transport context bagi
         * controller atau consumer HTTP yang belum dikonsolidasikan.
         *
         * Source of truth runtime:
         * - user: Laravel Auth Guard
         * - tenant: TenantContextInterface
         */
        $request->attributes->set(
            'authenticated_user_id',
            $userUuid,
        );

        $request->attributes->set(
            'authenticated_tenant_id',
            $tenantUuid,
        );

        try {
            return $next($request);
        } finally {
            /*
             * Hindari runtime state bocor pada long-running worker,
             * Octane, parallel testing, atau request berikutnya.
             */
            $this->tenantContext->clear();
            $this->auth->guard()->forgetUser();

            $request->attributes->remove(
                'authenticated_user_id',
            );

            $request->attributes->remove(
                'authenticated_tenant_id',
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractStringClaim(
        array $payload,
        string $claim,
    ): ?string {
        $value = $payload[$claim] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== ''
            ? $value
            : null;
    }

    private function contextErrorResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Authentication context missing or invalid.',
        ], Response::HTTP_FORBIDDEN);
    }
}
