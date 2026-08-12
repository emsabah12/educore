<?php

declare(strict_types=1);

namespace Modules\Academic\Http\Controllers\Api\v1;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Academic\Http\Requests\BulkGradeRequest;
use Modules\Academic\Services\BulkGradingService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class BulkGradingController
{
    public function __construct(
        private readonly BulkGradingService $bulkGradingService,
    ) {}

    public function storeBulk(BulkGradeRequest $request): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');
        $membershipId = $request->attributes->get('authenticated_membership_id');
        $authenticatedUserId = $request->attributes->get('authenticated_user_id');

        if (
            ! is_string($tenantId)
            || $tenantId === ''
            || ! is_string($membershipId)
            || $membershipId === ''
        ) {
            return response()->json([
                'status' => 'error',
                'message' => 'Authenticated tenant membership context is unavailable.',
            ], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validated();

        try {
            $result = $this->bulkGradingService->store(
                $tenantId,
                $membershipId,
                $validated,
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Nilai student berhasil disimpan.',
                'data' => [
                    'processed' => $result['processed'],
                ],
            ]);
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Bulk grading failed.', [
                'tenant_id' => $tenantId,
                'authenticated_user_id' => is_string($authenticatedUserId)
                    ? $authenticatedUserId
                    : null,
                'authenticated_membership_id' => $membershipId,
                'assessment_setting_id' => $validated['assessment_setting_id'] ?? null,
                'exception_class' => $exception::class,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Terjadi kesalahan saat menyimpan nilai student.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
