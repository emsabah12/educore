<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(
    basePath: dirname(__DIR__),
)
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(
        function (Middleware $middleware): void {
            $middleware->alias([
                'tenant.role' =>
                \Modules\Core\Authorization\Http\Middleware\CheckTenantRole::class,
                'tenant.permission' =>
                \Modules\Core\Authorization\Http\Middleware\CheckTenantPermission::class,
            ]);
        },
    )
    ->withExceptions(
        function (Exceptions $exceptions): void {
            /*
             * Public API dan explicit JSON clients selalu menerima
             * JSON exception responses.
             */
            $exceptions->shouldRenderJsonWhen(
                static fn(
                    Request $request,
                ): bool => $request->is('api/*')
                    || $request->expectsJson(),
            );

            /*
             * Canonical API validation contract.
             *
             * FormRequest tetap menjadi owner:
             * - rules;
             * - normalization;
             * - field-specific messages.
             *
             * Foundation hanya mengatur transport envelope.
             */
            $exceptions->render(
                static function (
                    ValidationException $exception,
                    Request $request,
                ): ?Response {
                    if (
                        ! $request->is('api/*')
                        && ! $request->expectsJson()
                    ) {
                        /*
                         * Non-API/browser validation tetap memakai
                         * behavior Laravel standard.
                         */
                        return null;
                    }

                    return ApiErrorResponse::make(
                        code: 'VALIDATION_FAILED',
                        message: 'The submitted data is invalid.',
                        status: Response::HTTP_UNPROCESSABLE_ENTITY,
                        errors: $exception->errors(),
                    );
                },
            );
        },
    )
    ->create();
