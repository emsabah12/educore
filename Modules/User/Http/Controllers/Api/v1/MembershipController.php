<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\Api\v1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
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

        if ($user === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $userId = trim(
            (string) $user->getAuthIdentifier(),
        );

        if ($userId === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Authenticated user context is invalid.',
            ], Response::HTTP_UNAUTHORIZED);
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

            return response()->json([
                'status' => 'success',
                'data' => $memberships,
            ], Response::HTTP_OK);
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

            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengambil data keanggotaan.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
