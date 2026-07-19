<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\Api\v1;

use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\User\Http\Requests\AssignRoleRequest;

final class ContextualRbacController extends Controller
{
    /**
     * Menyematkan (assign) Role Mikro ke dalam baris Contextual Membership Pegawai.
     */
    public function assignRoleToMembership(AssignRoleRequest $request, string $membershipId): JsonResponse
    {
        $validated = $request->validated();
        $roleId = $validated['role_id'];

        try {
            // 1. Verifikasi fisik keberadaan entitas membership target
            $membershipExists = DB::table('memberships')->where('id', $membershipId)->exists();
            if (!$membershipExists) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Entitas target membership tidak ditemukan di platform.'
                ], Response::HTTP_NOT_FOUND);
            }

            // 2. Transaksi Atomis untuk mendistribusikan data ke tabel pivot membership_roles
            DB::transaction(function () use ($membershipId, $roleId) {
                DB::table('membership_roles')->updateOrInsert(
                    ['membership_id' => $membershipId, 'role_id' => $roleId],
                    ['membership_id' => $membershipId, 'role_id' => $roleId] // Idempotent injection
                );
            });

            // 3. Catat log kesuksesan untuk audit keamanan internal (Audit Trail)
            Log::info("RBAC Assignment Success: Role {$roleId} attached to Membership {$membershipId}");

            return response()->json([
                'status' => 'success',
                'message' => 'Role mikro granular berhasil disematkan ke dalam konteks keanggotaan institusi.'
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            // Proteksi kebocoran pesan sistem ke klien luar
            Log::error("RBAC Assignment Fatal Error: " . $th->getMessage(), ['trace' => $th->getTraceAsString()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Gagal memproses penugasan hak akses akibat gangguan internal server.'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
