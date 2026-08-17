<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\Api\v1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\User\Application\Actions\SwitchMembership;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class SwitchMembershipController extends Controller
{
    public function __construct(
        private readonly SwitchMembership $switchMembership,
    ) {}

    /**
     * Validate target Membership dan menerbitkan bearer token baru
     * untuk Membership/Tenant context yang dipilih.
     *
     * Endpoint tetap stateless:
     * - tidak menyimpan active Membership di session;
     * - tidak menyimpan active Tenant di session;
     * - token lama tidak otomatis direvoke.
     */
    public function __invoke(
        Request $request,
        string $membership_id,
    ): JsonResponse {
        $user = $request->user();

        if ($user === null) {
            return ApiErrorResponse::make(
                code: 'AUTHENTICATION_REQUIRED',
                message: 'Unauthenticated. Invalid or missing identity context.',
                status: Response::HTTP_UNAUTHORIZED,
            );
        }

        $userId = trim(
            (string) $user->getAuthIdentifier(),
        );

        if ($userId === '') {
            return ApiErrorResponse::make(
                code: 'AUTHENTICATION_REQUIRED',
                message: 'Unauthenticated. Invalid or missing identity context.',
                status: Response::HTTP_UNAUTHORIZED,
            );
        }

        try {
            $result = $this->switchMembership->execute(
                authenticatedUserId: $userId,
                targetMembershipId: $membership_id,
            );

            /*
             * Jangan pernah log raw bearer token.
             */
            Log::info(
                'User membership context switched successfully.',
                [
                    'user_id' => $userId,
                    'selected_membership_id' =>
                    $result->membershipId,
                    'selected_tenant_id' =>
                    $result->tenantId,
                ],
            );

            return response()->json(
                [
                    'status' => 'success',
                    'message' => sprintf(
                        'Berhasil berpindah ke lembaga: %s',
                        $result->tenantName,
                    ),
                    'data' => [
                        'access_token' =>
                        $result->accessToken,
                        'token_type' => 'Bearer',
                        'expires_in' =>
                        $result->expiresIn,
                        'context' => [
                            'membership_id' =>
                            $result->membershipId,
                            'tenant_id' =>
                            $result->tenantId,
                            'tenant_name' =>
                            $result->tenantName,
                        ],
                    ],
                ],
                Response::HTTP_OK,
            );
        } catch (RuntimeException $exception) {
            /*
             * Detail ownership/lifecycle hanya untuk server log.
             */
            Log::warning(
                'User membership context switch rejected.',
                [
                    'user_id' => $userId,
                    'target_membership_id' =>
                    $membership_id,
                    'reason' =>
                    $exception->getMessage(),
                ],
            );

            return ApiErrorResponse::make(
                code: 'MEMBERSHIP_SWITCH_DENIED',
                message: 'Requested membership is not available for this user.',
                status: Response::HTTP_FORBIDDEN,
            );
        } catch (Throwable $exception) {
            Log::error(
                'User membership context switch failed.',
                [
                    'user_id' => $userId,
                    'target_membership_id' =>
                    $membership_id,
                    'exception' =>
                    $exception::class,
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
