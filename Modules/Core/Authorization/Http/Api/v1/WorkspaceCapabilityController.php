<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Http\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Core\Authorization\Exceptions\CapabilityProjectionContextException;
use Modules\Core\Authorization\Queries\WorkspaceCapabilityProjectionQuery;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class WorkspaceCapabilityController extends Controller
{
    public function __construct(
        private readonly WorkspaceCapabilityProjectionQuery $capabilityProjection,
    ) {}

    public function __invoke(): JsonResponse
    {
        try {
            $projection = $this->capabilityProjection
                ->execute();
        } catch (CapabilityProjectionContextException $exception) {
            Log::warning(
                'Workspace capability projection context was rejected.',
                [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
            );

            /*
             * Missing header pada normal HTTP flow tidak mencapai branch
             * ini karena InjectOrganizationalContext sudah fail-closed
             * dengan ORGANIZATIONAL_CONTEXT_REQUIRED.
             *
             * Branch ini menangani stale/mismatched context yang ditemukan
             * kembali oleh projection layer.
             */
            return ApiErrorResponse::make(
                code: 'ORGANIZATIONAL_CONTEXT_DENIED',
                message: 'Organizational context is invalid or no longer available.',
                status: Response::HTTP_FORBIDDEN,
            );
        } catch (Throwable $exception) {
            Log::error(
                'Workspace capability projection failed.',
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
                message: 'Failed to retrieve workspace capabilities.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json(
            [
                'status' => 'success',
                'data' => $projection->toArray(),
            ],
            Response::HTTP_OK,
        );
    }
}
