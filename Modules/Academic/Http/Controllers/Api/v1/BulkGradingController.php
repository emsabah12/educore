<?php

declare(strict_types=1);

namespace Modules\Academic\Http\Controllers\Api\v1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Academic\Http\Requests\BulkGradeRequest;
use Throwable;

final class BulkGradingController
{
    /**
     * Menyimpan nilai student secara bulk.
     *
     * Prinsip keamanan:
     * 1. Input divalidasi melalui FormRequest.
     * 2. Tenant aktif diambil dari session.
     * 3. Assessment setting diverifikasi terhadap tenant aktif.
     * 4. Student diverifikasi terhadap tenant aktif.
     * 5. Semua perubahan dilakukan dalam transaction.
     */
    public function storeBulk(BulkGradeRequest $request): JsonResponse
    {
        $tenantId = session('active_tenant_id');

        if (!is_string($tenantId) || $tenantId === '') {
            return response()->json([
                'message' => 'Tenant context tidak ditemukan.',
            ], 403);
        }

        $validated = $request->validated();

        try {
            DB::transaction(function () use ($validated, $tenantId): void {
                $assessmentSetting = DB::table('assessment_settings')
                    ->where('id', $validated['assessment_setting_id'])
                    ->where('tenant_id', $tenantId)
                    ->first();

                if ($assessmentSetting === null) {
                    abort(403, 'Assessment setting bukan milik tenant aktif.');
                }

                $studentIds = collect($validated['grades'])
                    ->pluck('student_id')
                    ->unique()
                    ->values();

                $validStudentCount = DB::table('students')
                    ->where('tenant_id', $tenantId)
                    ->whereIn('id', $studentIds)
                    ->count();

                if ($validStudentCount !== $studentIds->count()) {
                    abort(
                        403,
                        'Satu atau lebih student tidak terdaftar pada tenant aktif.'
                    );
                }

                $now = now();

                $rows = collect($validated['grades'])
                    ->map(static function (array $grade) use (
                        $validated,
                        $tenantId,
                        $now
                    ): array {
                        return [
                            'id' => (string) \Illuminate\Support\Str::uuid(),
                            'tenant_id' => $tenantId,
                            'assessment_setting_id' =>
                            $validated['assessment_setting_id'],
                            'student_id' => $grade['student_id'],
                            'teacher_id' => $validated['teacher_id'],
                            'score' => $grade['score'],
                            'notes' => $grade['notes'] ?? null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    })
                    ->all();

                DB::table('student_grades')->upsert(
                    $rows,
                    [
                        'assessment_setting_id',
                        'student_id',
                    ],
                    [
                        'teacher_id',
                        'score',
                        'notes',
                        'updated_at',
                    ]
                );
            });

            return response()->json([
                'message' => 'Nilai student berhasil disimpan.',
            ], 200);
        } catch (Throwable $exception) {
            Log::error(
                'Bulk grading failed.',
                [
                    'tenant_id' => $tenantId,
                    'assessment_setting_id' =>
                    $validated['assessment_setting_id'] ?? null,
                    'exception' => $exception,
                ]
            );

            if ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                throw $exception;
            }

            return response()->json([
                'message' => 'Terjadi kesalahan saat menyimpan nilai student.',
            ], 500);
        }
    }
}
