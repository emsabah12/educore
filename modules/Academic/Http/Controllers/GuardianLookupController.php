<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Academic\Application\Guardian\GuardianDirectory;
use Modules\Academic\Application\Student\StudentManageAccess;
use Modules\Core\Domain\Tenancy\TenantContext;

/**
 * Cari orang tua/wali yang sudah tercatat dari form Tambah siswa (PRD-002 §4.5, revisi
 * [OWNER, 2026-10-28]): Admin Yayasan & Operator Akademik (pengelola siswa di lembaga mana pun).
 * Lainnya → 403. Dibatasi throttle di route.
 */
final class GuardianLookupController
{
    public const TOO_SHORT = 'Ketik minimal 3 huruf nama atau 4 angka nomor HP.';

    public function __invoke(Request $request, StudentManageAccess $access, TenantContext $tenantContext, GuardianDirectory $directory): JsonResponse
    {
        if (! $access->managesAnywhere()) {
            abort(403);
        }

        $query = GuardianDirectory::parse($request->query('q'));

        if ($query === null) {
            return response()->json(['message' => self::TOO_SHORT, 'results' => []], 422);
        }

        return response()->json(['results' => $directory->search($tenantContext->require()->tenantId, $query)]);
    }
}
