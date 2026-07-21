<?php

namespace Modules\Academic\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Academic\Http\Requests\BulkGradeRequest;
use Exception;

class BulkGradingController extends Controller
{
    public function storeBulk(BulkGradeRequest $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        return DB::transaction(function () use ($request, $tenantId) {
            $settingId = $request->input('assessment_setting_id');
            $teacherId = $request->input('teacher_id');

            // Cross-Tenant Validation: Pastikan komponen penilaian ini milik tenant yang bersangkutan
            $settingExists = DB::table('assessment_settings')
                ->where('id', $settingId)
                ->where('tenant_id', $tenantId)
                ->exists();

            if (!$settingExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pengaturan komponen penilaian tidak valid untuk Tenant Anda.'
                ], 403);
            }

            foreach ($request->input('grades') as $gradeItem) {
                // Cross-Tenant Validation: Pastikan santri terdaftar di tenant yang sama
                $santriExists = DB::table('santris')
                    ->where('id', $gradeItem['santri_id'])
                    ->where('tenant_id', $tenantId)
                    ->exists();

                if (!$santriExists) {
                    throw new Exception("Santri dengan ID {$gradeItem['santri_id']} berada di luar yurisdiksi Tenant Anda.");
                }

                DB::table('student_grades')->updateOrInsert(
                    [
                        'tenant_id' => $tenantId,
                        'assessment_setting_id' => $settingId,
                        'santri_id' => $gradeItem['santri_id']
                    ],
                    [
                        'id' => Str::uuid()->toString(),
                        'teacher_id' => $teacherId,
                        'score' => $gradeItem['score'],
                        'notes' => $gradeItem['notes'] ?? null,
                        'updated_at' => now()
                    ]
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Seluruh nilai berhasil diproses secara massal.'
            ], 200);
        });
    }
}
