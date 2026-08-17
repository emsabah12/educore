<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\Api\v1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\User\Application\Actions\ListMyMemberships;
use Modules\User\Application\DTO\MembershipSummary;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class MembershipController extends Controller
{
    public function __construct(
        private readonly ListMyMemberships $listMyMemberships,
    ) {}

    public function index(
        Request $request,
    ): JsonResponse {
        $user = $request->user();

        /*
         * Route production sudah dijaga InjectAuthenticatedUser.
         * Guard ini tetap dipertahankan sebagai defense-in-depth
         * jika controller dipakai dari route/composition lain.
         */
        if ($user === null) {
            return $this->authenticationRequiredResponse();
        }

        $userId = trim(
            (string) $user->getAuthIdentifier(),
        );

        if ($userId === '') {
            return $this->authenticationRequiredResponse();
        }

        try {
            $memberships = $this->listMyMemberships
                ->execute($userId)
                ->map(
                    static fn(
                        MembershipSummary $membership,
                    ): array => $membership->toArray(),
                )
                ->values()
                ->all();

            return response()->json(
                [
                    'status' => 'success',
                    'data' => $memberships,
                ],
                Response::HTTP_OK,
            );
        } catch (Throwable $exception) {
            Log::error(
                'Failed to list authenticated user memberships.',
                [
                    'user_id' => $userId,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
            );

            if (app()->environment('testing')) {
                throw $exception;
            }

            return ApiErrorResponse::make(
                code: 'INTERNAL_SERVER_ERROR',
                message: 'Failed to retrieve memberships.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }

    private function authenticationRequiredResponse(): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'AUTHENTICATION_REQUIRED',
            message: 'Unauthenticated. Invalid or missing identity context.',
            status: Response::HTTP_UNAUTHORIZED,
        );
    }
}
