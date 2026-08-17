<?php

declare(strict_types=1);

namespace Modules\Core\Organization\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\Core\Organization\Contracts\OrganizationalContextInterface;
use Modules\Core\Organization\Contracts\OrganizationalContextResolverInterface;
use Modules\Core\Organization\Exceptions\OrganizationalContextException;
use Modules\Core\Support\Uuid\UuidV7;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class InjectOrganizationalContext
{
    public const HEADER =
    'X-EduCore-Organizational-Assignment-Id';

    public function __construct(
        private readonly OrganizationalContextResolverInterface $resolver,
        private readonly OrganizationalContextInterface $organizationalContext,
    ) {}

    /**
     * Resolve verified organizational context for this request.
     *
     * Middleware harus dijalankan setelah authentication dan
     * Tenant/Membership context terbentuk.
     *
     * Header hanyalah locator dan tidak pernah menjadi
     * authorization authority.
     *
     * @param Closure(Request): Response $next
     */
    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        /*
         * Defense-in-depth terhadap stale execution state.
         */
        $this->organizationalContext->clear();

        $assignmentId = $request->header(
            self::HEADER,
        );

        if (
            ! is_string($assignmentId)
            || trim($assignmentId) === ''
        ) {
            return ApiErrorResponse::make(
                code: 'ORGANIZATIONAL_CONTEXT_REQUIRED',
                message: 'Organizational workspace is required for this operation.',
                status: Response::HTTP_FORBIDDEN,
            );
        }

        $assignmentId = trim(
            $assignmentId,
        );

        if (! UuidV7::validate($assignmentId)) {
            return ApiErrorResponse::make(
                code: 'INVALID_ORGANIZATIONAL_ASSIGNMENT_ID',
                message: 'Organizational assignment identifier is invalid.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            /*
             * Canonical resolver sendiri yang membuktikan:
             *
             * - current Tenant;
             * - current Membership;
             * - assignment ownership;
             * - assignment ACTIVE;
             * - Organization ACTIVE;
             * - OrganizationUnit ACTIVE dan konsisten.
             */
            $this->resolver->resolve(
                $assignmentId,
            );
        } catch (
            OrganizationalContextException $exception
        ) {
            $this->organizationalContext->clear();

            Log::warning(
                'Organizational context resolution denied.',
                [
                    'organizational_assignment_id' =>
                    $assignmentId,
                    'authenticated_membership_id' =>
                    $request->attributes->get(
                        'authenticated_membership_id',
                    ),
                    'authenticated_tenant_id' =>
                    $request->attributes->get(
                        'authenticated_tenant_id',
                    ),
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'reason' =>
                    $exception->getMessage(),
                ],
            );

            return ApiErrorResponse::make(
                code: 'ORGANIZATIONAL_CONTEXT_DENIED',
                message: 'Organizational workspace is not available for this membership.',
                status: Response::HTTP_FORBIDDEN,
            );
        } catch (Throwable $exception) {
            $this->organizationalContext->clear();

            Log::error(
                'Organizational context resolution failed unexpectedly.',
                [
                    'organizational_assignment_id' =>
                    $assignmentId,
                    'authenticated_membership_id' =>
                    $request->attributes->get(
                        'authenticated_membership_id',
                    ),
                    'authenticated_tenant_id' =>
                    $request->attributes->get(
                        'authenticated_tenant_id',
                    ),
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'exception' =>
                    $exception::class,
                    'message' =>
                    $exception->getMessage(),
                ],
            );

            return ApiErrorResponse::make(
                code: 'ORGANIZATIONAL_CONTEXT_RESOLUTION_FAILED',
                message: 'Organizational workspace could not be resolved.',
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        try {
            return $next($request);
        } finally {
            /*
             * OrganizationalContext hanya hidup selama
             * operational request ini.
             */
            $this->organizationalContext->clear();
        }
    }
}
