<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\Api\v1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\Core\Identity\Models\User;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * §Kelola Anggota & Role — daftar anggota (Membership AKTIF) dalam
 * tenant yang sedang otentikasi, beserta role yang sudah mereka
 * punya saat ini. Digerbang 'tenant.role:admin' di route (sama
 * persis dengan AssignMembershipRoleController) — hanya admin yang
 * boleh melihat/mengelola daftar ini, bukan permission granular
 * (lihat komentar di Routes/api.php).
 */
final class TenantMembershipController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );

        if (! is_string($tenantId) || $tenantId === '') {
            return ApiErrorResponse::make(
                code: 'AUTHENTICATION_CONTEXT_DENIED',
                message: 'Authentication context missing or invalid.',
                status: Response::HTTP_FORBIDDEN,
            );
        }

        try {
            $memberships = Membership::query()
                ->where('tenant_id', $tenantId)
                ->where('status', 'ACTIVE')
                ->with([
                    'person',
                    'roles',
                ])
                ->orderBy('created_at')
                ->get();

            $personIds = $memberships
                ->pluck('person_id')
                ->filter()
                ->values()
                ->all();

            $usersByPersonId = User::query()
                ->whereIn('person_id', $personIds)
                ->get()
                ->keyBy('person_id');

            $data = $memberships->map(
                function (Membership $membership) use ($usersByPersonId): array {
                    $user = $usersByPersonId->get(
                        $membership->person_id,
                    );

                    return [
                        'membership_id' => (string) $membership->id,
                        'person_name' => $membership->person?->name,
                        'email' => $user?->email,
                        'roles' => $membership->roles
                            ->map(
                                static fn ($role): array => [
                                    'id' => (string) $role->id,
                                    'name' => $role->name,
                                    'display_name' => $role->display_name,
                                ],
                            )
                            ->values()
                            ->all(),
                    ];
                },
            )->values()->all();

            return response()->json(
                [
                    'status' => 'success',
                    'data' => $data,
                ],
            );
        } catch (Throwable $exception) {
            Log::error(
                'Failed to list tenant memberships.',
                [
                    'tenant_id' => $tenantId,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
            );

            return ApiErrorResponse::make(
                code: 'INTERNAL_SERVER_ERROR',
                message: 'Failed to retrieve tenant memberships.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }
}
