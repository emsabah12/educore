<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\Core\Identity\Models\User;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticatedContextController extends Controller
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
    ) {}

    /**
     * Return canonical authenticated frontend bootstrap context.
     *
     * InjectTenantContext sudah memvalidasi:
     * - bearer token,
     * - active User,
     * - Person ownership,
     * - active Membership,
     * - active Tenant.
     *
     * Controller ini hanya membentuk read projection untuk frontend.
     */
    public function __invoke(
        Request $request,
    ): JsonResponse {
        $user = $request->user();

        $membershipId = $request->attributes->get(
            'authenticated_membership_id',
        );

        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );

        $tenant = $this->tenantContext
            ->getCurrentTenant();

        if (
            ! $user instanceof User
            || ! is_string($membershipId)
            || ! UuidV7::validate($membershipId)
            || ! is_string($tenantId)
            || ! UuidV7::validate($tenantId)
            || $tenant === null
            || (string) $tenant->getKey() !== $tenantId
        ) {
            return $this->invalidContextResponse(
                userId: $user instanceof User
                    ? (string) $user->getKey()
                    : null,
                membershipId: is_string($membershipId)
                    ? $membershipId
                    : null,
                tenantId: is_string($tenantId)
                    ? $tenantId
                    : null,
            );
        }

        $user->loadMissing(
            'person',
        );

        $person = $user->person;

        if ($person === null) {
            return $this->invalidContextResponse(
                userId: (string) $user->getKey(),
                membershipId: $membershipId,
                tenantId: $tenantId,
            );
        }

        return response()->json(
            [
                'status' => 'success',
                'data' => [
                    'user' => [
                        'id' =>
                        (string) $user->getKey(),
                        'email' =>
                        (string) $user->email,
                    ],
                    'person' => [
                        'id' =>
                        (string) $person->getKey(),
                        'name' =>
                        (string) $person->name,
                    ],
                    'membership' => [
                        'id' => $membershipId,
                        'status' => 'ACTIVE',
                    ],
                    'tenant' => [
                        'id' => $tenantId,
                        'name' =>
                        (string) $tenant->name,
                        'subdomain' =>
                        $tenant->subdomain !== null
                            ? (string) $tenant->subdomain
                            : null,
                    ],
                ],
            ],
            Response::HTTP_OK,
        );
    }

    private function invalidContextResponse(
        ?string $userId,
        ?string $membershipId,
        ?string $tenantId,
    ): JsonResponse {
        Log::warning(
            'Authenticated bootstrap context could not be projected.',
            [
                'user_id' => $userId,
                'membership_id' => $membershipId,
                'tenant_id' => $tenantId,
            ],
        );

        return ApiErrorResponse::make(
            code: 'AUTHENTICATION_CONTEXT_DENIED',
            message: 'Authentication context missing or invalid.',
            status: Response::HTTP_FORBIDDEN,
        );
    }
}
