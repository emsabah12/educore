<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class MembershipResolutionController extends Controller
{
    /**
     * Mengambil seluruh daftar aliansi lembaga (tenants) yang dimiliki oleh user aktif.
     */
    public function index(): JsonResponse
    {
        try {
            $userId = Auth::id();

            $memberships = DB::table('memberships')
                ->join('tenants', 'memberships.tenant_id', '=', 'tenants.id')
                ->where('memberships.user_id', $userId)
                ->where('memberships.status', 'ACTIVE')
                ->select([
                    'memberships.id as membership_id',
                    'memberships.role as macro_role',
                    'memberships.status as membership_status',
                    'tenants.id as tenant_id',
                    'tenants.name as tenant_name',
                    'tenants.subdomain as tenant_subdomain'
                ])
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $memberships
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            Log::error("Failed fetching user memberships: " . $th->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengambil data keanggotaan internal.'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Memproses perpindahan konteks institusi aktif (Switch Tenant Context).
     */
    public function switchContext(string $membershipId): JsonResponse
    {
        try {
            $userId = Auth::id();

            // Guard: Validasi kepemilikan fisik dari membership id yang dikirimkan
            $membership = DB::table('memberships')
                ->join('tenants', 'memberships.tenant_id', '=', 'tenants.id')
                ->where('memberships.id', $membershipId)
                ->where('memberships.user_id', $userId)
                ->where('memberships.status', 'ACTIVE')
                ->select('memberships.id', 'memberships.tenant_id', 'tenants.name')
                ->first();

            if (!$membership) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Akses ditolak: Anda tidak terdaftar atau tidak aktif pada lembaga ini.'
                ], Response::HTTP_FORBIDDEN);
            }

            // PERBAIKAN DEFENSIVE: Amankan injeksi session jika store tersedia (mendukung API stateless & Web stateful)
            if (request()->hasSession()) {
                session(['active_membership_id' => $membership->id]);
                session(['active_tenant_id' => $membership->tenant_id]);
            }

            Log::info("User {$userId} successfully switched context to Tenant: {$membership->name}");

            return response()->json([
                'status' => 'success',
                'message' => "Berhasil beralih konteks ke lumbung institusi: {$membership->name}",
                'context' => [
                    'active_membership_id' => $membership->id,
                    'active_tenant_id' => $membership->tenant_id
                ]
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            // JIKA LINGKUNGAN TESTING: Lemparkan eror asli ke terminal agar tidak disembunyikan oleh HTTP 500
            if (app()->environment('testing')) {
                throw $th;
            }

            Log::error("Fatal context switching error: " . $th->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal memproses peralihan hak akses lembaga.'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
