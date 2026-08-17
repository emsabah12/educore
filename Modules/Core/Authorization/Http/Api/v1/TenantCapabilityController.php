<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Http\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Core\Authorization\Exceptions\CapabilityProjectionContextException;
use Modules\Core\Authorization\Queries\TenantCapabilityProjectionQuery;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class TenantCapabilityController extends Controller
{
    public function __construct(
        private readonly TenantCapabilityProjectionQuery $capabilityProjection,
    ) {}

    public function __invoke(): JsonResponse
    {
        try {
            $projection = $this->capabilityProjection
                ->execute();
        } catch (CapabilityProjectionContextException $exception) {
            Log::warning(
                'Tenant capability projection context was rejected.',
                [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
            );

            return ApiErrorResponse::make(
                code: 'AUTHENTICATION_CONTEXT_DENIED',
                message: 'Authentication context missing or invalid.',
                status: Response::HTTP_FORBIDDEN,
            );
        } catch (Throwable $exception) {
            Log::error(
                'Tenant capability projection failed.',
                [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
            );

            /*
             * Unexpected failures tetap dilempar di test environment
             * sehingga regression tidak tersembunyi di balik generic 500.
             */
            if (app()->environment('testing')) {
                throw $exception;
            }

            return ApiErrorResponse::make(
                code: 'INTERNAL_SERVER_ERROR',
                message: 'Failed to retrieve tenant capabilities.',
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
