<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Http\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Core\Authorization\Queries\RoleCatalogQuery;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class RoleCatalogController extends Controller
{
    public function __construct(
        private readonly RoleCatalogQuery $roleCatalogQuery,
    ) {}

    public function __invoke(): JsonResponse
    {
        try {
            $roles = $this->roleCatalogQuery->execute();
        } catch (Throwable $exception) {
            Log::error(
                'Authorization role catalog discovery failed.',
                [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
            );

            /*
             * Dalam test environment exception tetap dilempar agar
             * regression/infrastructure failure tidak tersembunyi oleh
             * generic HTTP response.
             */
            if (app()->environment('testing')) {
                throw $exception;
            }

            return ApiErrorResponse::make(
                code: 'INTERNAL_SERVER_ERROR',
                message: 'Failed to retrieve authorization role catalog.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json(
            [
                'status' => 'success',
                'data' => $roles,
            ],
            Response::HTTP_OK,
        );
    }
}
