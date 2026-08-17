<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\Api\v1;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\User\Application\Actions\ListMyWorkspaces;
use Modules\User\Application\DTO\WorkspaceSummary;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class WorkspaceController extends Controller
{
    public function __construct(
        private readonly ListMyWorkspaces $listMyWorkspaces,
    ) {}

    public function index(): JsonResponse
    {
        try {
            $result = $this->listMyWorkspaces
                ->execute();

            return response()->json(
                [
                    'status' => 'success',
                    'data' => [
                        'tenant' => [
                            'id' => $result->tenantId,
                            'name' => $result->tenantName,
                        ],
                        'workspaces' => $result->workspaces
                            ->map(
                                static fn(
                                    WorkspaceSummary $workspace,
                                ): array => $workspace->toArray(),
                            )
                            ->values()
                            ->all(),
                    ],
                ],
                Response::HTTP_OK,
            );
        } catch (Throwable $exception) {
            Log::error(
                'Failed to discover authenticated user workspaces.',
                [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
            );

            if (app()->environment('testing')) {
                throw $exception;
            }

            return ApiErrorResponse::make(
                code: 'INTERNAL_SERVER_ERROR',
                message: 'Failed to retrieve available workspaces.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }
}
