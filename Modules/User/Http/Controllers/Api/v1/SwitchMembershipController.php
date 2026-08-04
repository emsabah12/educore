<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\Api\v1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\User\Application\Actions\SwitchMembership;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class SwitchMembershipController extends Controller
{
    public function __construct(
        private readonly SwitchMembership $switchMembership,
    ) {}

    /**
     * Memvalidasi target membership dan mengembalikan context terpilih.
     *
     * Endpoint ini stateless:
     * - tidak menyimpan active membership di session;
     * - tidak menyimpan active tenant di session;
     * - tidak mengubah TenantContext runtime untuk request berikutnya.
     *
     * Client bertanggung jawab memakai context yang dikembalikan untuk
     * memperoleh atau mengirim authentication context berikutnya.
     */
    public function __invoke(
        Request $request,
        string $membership_id,
    ): JsonResponse {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $userId = trim(
            (string) $user->getAuthIdentifier(),
        );

        if ($userId === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Authenticated user context is invalid.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $result = $this->switchMembership->execute(
                authenticatedUserId: $userId,
                targetMembershipId: $membership_id,
            );

            Log::info(
                'User membership context selection validated successfully.',
                [
                    'user_id' => $userId,
                    'selected_membership_id' => $result->membershipId,
                    'selected_tenant_id' => $result->tenantId,
                ],
            );

            return response()->json([
                'status' => 'success',
                'message' => sprintf(
                    'Berhasil memilih konteks lembaga: %s',
                    $result->tenantName,
                ),
                'context' => [
                    'membership_id' => $result->membershipId,
                    'tenant_id' => $result->tenantId,
                    'tenant_name' => $result->tenantName,
                ],
            ], Response::HTTP_OK);
        } catch (RuntimeException $exception) {
            Log::warning(
                'User membership context selection rejected.',
                [
                    'user_id' => $userId,
                    'target_membership_id' => $membership_id,
                    'reason' => $exception->getMessage(),
                ],
            );

            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], Response::HTTP_FORBIDDEN);
        } catch (Throwable $exception) {
            Log::error(
                'User membership context selection failed.',
                [
                    'user_id' => $userId,
                    'target_membership_id' => $membership_id,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
            );

            if (app()->environment('testing')) {
                throw $exception;
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Gagal memproses pemilihan konteks lembaga.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
