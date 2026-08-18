<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\Browser\v1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\Auth\BrowserSession\Contracts\BrowserSessionCredentialVaultInterface;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\User\Application\Actions\SwitchMembership;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class BrowserSwitchMembershipController extends Controller
{
    public function __construct(
        private readonly SwitchMembership $switchMembership,
        private readonly BrowserSessionCredentialVaultInterface $credentialVault,
    ) {}

    public function __invoke(
        Request $request,
        string $membership_id,
    ): JsonResponse {
        $targetMembershipId = trim($membership_id);

        if (! UuidV7::validate($targetMembershipId)) {
            return ApiErrorResponse::make(
                code: 'INVALID_BROWSER_MEMBERSHIP_ID',
                message: 'Browser membership identifier is invalid.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $authenticatedUserId = $this->credentialVault->userId();
        } catch (Throwable $exception) {
            Log::error(
                'Browser membership switch could not resolve BrowserSession identity.',
                [
                    'target_membership_id' => $targetMembershipId,
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'exception' => $exception::class,
                ],
            );

            return $this->sessionUnavailableResponse();
        }

        if ($authenticatedUserId === null) {
            return ApiErrorResponse::make(
                code: 'BROWSER_SESSION_AUTHENTICATION_REQUIRED',
                message: 'Authenticated browser session is required.',
                status: Response::HTTP_UNAUTHORIZED,
            );
        }

        try {
            $result = $this->switchMembership->execute(
                authenticatedUserId: $authenticatedUserId,
                targetMembershipId: $targetMembershipId,
            );
        } catch (RuntimeException $exception) {
            Log::warning(
                'Browser membership switch rejected by canonical Membership policy.',
                [
                    'user_id' => $authenticatedUserId,
                    'target_membership_id' => $targetMembershipId,
                    'reason' => $exception->getMessage(),
                ],
            );

            return ApiErrorResponse::make(
                code: 'MEMBERSHIP_SWITCH_DENIED',
                message: 'Requested membership is not available for this user.',
                status: Response::HTTP_FORBIDDEN,
            );
        } catch (Throwable $exception) {
            Log::error(
                'Browser membership switch failed before credential persistence.',
                [
                    'user_id' => $authenticatedUserId,
                    'target_membership_id' => $targetMembershipId,
                    'exception' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'INTERNAL_SERVER_ERROR',
                message: 'An unexpected error occurred.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        try {
            $this->credentialVault->storeMembershipCredential(
                $result->membershipId,
                $result->accessToken,
            );
        } catch (Throwable $exception) {
            Log::error(
                'Browser membership switch could not persist the canonical credential.',
                [
                    'user_id' => $authenticatedUserId,
                    'target_membership_id' => $targetMembershipId,
                    'selected_membership_id' => $result->membershipId,
                    'selected_tenant_id' => $result->tenantId,
                    'exception' => $exception::class,
                ],
            );

            return $this->sessionUnavailableResponse();
        }

        Log::info(
            'Browser membership credential prepared successfully.',
            [
                'user_id' => $authenticatedUserId,
                'selected_membership_id' => $result->membershipId,
                'selected_tenant_id' => $result->tenantId,
            ],
        );

        return response()->json(
            [
                'status' => 'success',
                'data' => [
                    'membership_id' => $result->membershipId,
                    'tenant_id' => $result->tenantId,
                    'tenant_name' => $result->tenantName,
                ],
            ],
            Response::HTTP_OK,
        );
    }

    private function sessionUnavailableResponse(): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'BROWSER_SESSION_UNAVAILABLE',
            message: 'Unable to update the secure browser session.',
            status: Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
