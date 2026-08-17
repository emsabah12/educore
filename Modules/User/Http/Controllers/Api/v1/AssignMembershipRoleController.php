<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\Api\v1;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\User\Application\Actions\AssignRoleToMembership;
use Modules\User\Http\Requests\AssignMembershipRoleRequest;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class AssignMembershipRoleController extends Controller
{
    public function __construct(
        private readonly AssignRoleToMembership $assignRoleToMembership,
    ) {}

    public function __invoke(
        AssignMembershipRoleRequest $request,
        string $target_membership_id,
    ): JsonResponse {
        $validated = $request->validated();

        try {
            $result = $this->assignRoleToMembership->execute(
                targetMembershipId: $target_membership_id,
                roleId: (string) $validated['role_id'],
            );

            Log::info(
                'Membership role assigned successfully.',
                [
                    'actor_user_id' => $result->actorUserId,
                    'actor_membership_id' => $result->actorMembershipId,
                    'tenant_id' => $result->tenantId,
                    'target_membership_id' =>
                    $result->targetMembershipId,
                    'role_id' => $result->roleId,
                ],
            );

            return response()->json(
                [
                    'status' => 'success',
                    'message' =>
                    'Role berhasil ditetapkan pada target membership.',
                    'data' => [
                        'target_membership_id' =>
                        $result->targetMembershipId,
                        'role_id' => $result->roleId,
                    ],
                ],
                Response::HTTP_OK,
            );
        } catch (RuntimeException $exception) {
            /*
             * Internal rejection reason tetap berguna untuk observability,
             * tetapi tidak menjadi public API contract.
             */
            Log::warning(
                'Membership role assignment rejected.',
                [
                    'target_membership_id' =>
                    $target_membership_id,
                    'reason' =>
                    $exception->getMessage(),
                ],
            );

            return ApiErrorResponse::make(
                code: 'MEMBERSHIP_ROLE_ASSIGNMENT_REJECTED',
                message: 'Requested membership or role is not available.',
                status: Response::HTTP_NOT_FOUND,
            );
        } catch (Throwable $exception) {
            Log::error(
                'Membership role assignment failed.',
                [
                    'target_membership_id' =>
                    $target_membership_id,
                    'exception' => $exception::class,
                    'message' =>
                    $exception->getMessage(),
                ],
            );

            if (app()->environment('testing')) {
                throw $exception;
            }

            return ApiErrorResponse::make(
                code: 'INTERNAL_SERVER_ERROR',
                message: 'An unexpected error occurred.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }
}
