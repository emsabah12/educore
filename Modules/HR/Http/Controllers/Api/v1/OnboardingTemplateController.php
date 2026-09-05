<?php

declare(strict_types=1);

namespace Modules\HR\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\HR\Http\Requests\StoreOnboardingTemplateRequest;
use Modules\HR\Models\OnboardingTemplate;
use Modules\HR\Models\OnboardingTemplateTask;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class OnboardingTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        $perPage = max(
            1,
            min(
                (int) $request->query('per_page', '15'),
                100,
            ),
        );

        $templates = OnboardingTemplate::query()
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $templates->items(),
            'meta' => [
                'current_page' => $templates->currentPage(),
                'last_page' => $templates->lastPage(),
                'per_page' => $templates->perPage(),
                'total' => $templates->total(),
            ],
        ]);
    }

    public function store(StoreOnboardingTemplateRequest $request): JsonResponse
    {
        $tenantId = $request->attributes->get(
            'authenticated_tenant_id',
        );

        if (! $this->isCanonicalUuid($tenantId)) {
            return $this->authenticationContextDeniedResponse();
        }

        /**
         * @var array{
         *     code: string,
         *     name: string,
         *     tasks: array<int, array{
         *         code: string,
         *         title: string,
         *         category: string,
         *         sequence: int,
         *         is_required?: bool,
         *         requires_evidence?: bool,
         *     }>|null,
         * } $payload
         */
        $payload = $request->validated();

        try {
            $template = DB::transaction(function () use ($payload): OnboardingTemplate {
                $template = OnboardingTemplate::create([
                    'code' => $payload['code'],
                    'name' => $payload['name'],
                ]);

                foreach ($payload['tasks'] ?? [] as $task) {
                    OnboardingTemplateTask::create([
                        'template_id' => $template->id,
                        'code' => $task['code'],
                        'title' => $task['title'],
                        'category' => $task['category'],
                        'sequence' => $task['sequence'],
                        'is_required' => $task['is_required'] ?? true,
                        'requires_evidence' => $task['requires_evidence'] ?? false,
                    ]);
                }

                return $template;
            });
        } catch (Throwable $exception) {
            Log::error(
                'OnboardingTemplate creation failed.',
                [
                    'tenant_id' => $tenantId,
                    'exception_class' => $exception::class,
                ],
            );

            return ApiErrorResponse::make(
                code: 'ONBOARDING_TEMPLATE_CREATION_FAILED',
                message: 'Failed to persist OnboardingTemplate record.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Onboarding template created.',
            'data' => $template->load('tasks'),
        ], 201);
    }

    /**
     * @phpstan-assert-if-true string $value
     */
    private function isCanonicalUuid(mixed $value): bool
    {
        return is_string($value)
            && Str::isUuid(trim($value));
    }

    private function authenticationContextDeniedResponse(): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'AUTHENTICATION_CONTEXT_DENIED',
            message: 'Authentication context missing or invalid.',
            status: Response::HTTP_FORBIDDEN,
        );
    }
}
