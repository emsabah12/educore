<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Core\Http\Responses\ApiErrorResponse;
use Modules\Core\Identity\Models\User;
use Modules\Core\Person\Models\PersonModel;
use Symfony\Component\HttpFoundation\Response;

final class AuthIdentityController extends Controller
{
    /**
     * Return canonical global authenticated identity projection.
     *
     * Authentication has already been established by the transport-aware
     * identity middleware. This endpoint intentionally does not establish or
     * expose Membership, Tenant, Workspace, Role, or Permission context.
     */
    public function __invoke(
        Request $request,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            Log::warning(
                'Global identity introspection reached controller without canonical User.',
                [
                    'path' => $request->path(),
                    'method' => $request->method(),
                ],
            );

            return $this->authenticationRequiredResponse();
        }

        $user->loadMissing(
            'person',
        );

        $person = $user->person;

        if (! $person instanceof PersonModel) {
            Log::warning(
                'Global identity introspection could not resolve canonical Person.',
                [
                    'user_id' => (string) $user->getKey(),
                    'path' => $request->path(),
                    'method' => $request->method(),
                ],
            );

            return $this->authenticationRequiredResponse();
        }

        $username = $user->getAttribute(
            'username',
        );

        return response()->json(
            [
                'status' => 'success',
                'data' => [
                    'context_type' => 'identity',
                    'user' => [
                        'id' =>
                            (string) $user->getKey(),
                        'name' =>
                            (string) $person->name,
                        'email' =>
                            (string) $user->email,
                        'username' =>
                            is_string($username)
                            && trim($username) !== ''
                                ? $username
                                : null,
                    ],
                    'platform' => [
                        'is_superadmin' =>
                            (bool) $user->is_superadmin,
                    ],
                ],
            ],
            Response::HTTP_OK,
        );
    }

    private function authenticationRequiredResponse(): JsonResponse
    {
        return ApiErrorResponse::make(
            code: 'AUTHENTICATION_REQUIRED',
            message:
                'Unauthenticated. Invalid or missing identity context.',
            status: Response::HTTP_UNAUTHORIZED,
        );
    }
}
