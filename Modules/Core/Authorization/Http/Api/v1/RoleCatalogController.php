<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Http\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Core\Authorization\Queries\RoleCatalogQuery;
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

            if (app()->environment('testing')) {
                throw $exception;
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve authorization role catalog.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json([
            'status' => 'success',
            'data' => $roles,
        ], Response::HTTP_OK);
    }
}
