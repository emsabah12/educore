<?php

declare(strict_types=1);

namespace Modules\Academic\Http\Controllers\Api\v1;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Academic\Http\Requests\BulkGradeRequest;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class BulkGradingController
{
    /**
     * Menyimpan nilai student secara bulk.
     *
     * Security boundaries:
     *
     * 1. Input divalidasi melalui FormRequest.
     * 2. Tenant aktif berasal dari authenticated request context.
     * 3. Identitas penginput berasal dari authenticated user,
     *    bukan dari payload client.
     * 4. Assessment setting diverifikasi terhadap tenant aktif.
     * 5. Student diverifikasi terhadap tenant aktif.
     * 6. Semua perubahan dilakukan dalam database transaction.
     *
     * Catatan:
     * Resolusi authenticated user -> employee belum dilakukan di sini.
     * Karena kolom student_grades.teacher_id saat ini FK ke employees.id,
     * resolusi tersebut harus dilakukan pada application/service layer
     * sebelum implementasi final.
     */
    public function storeBulk(BulkGradeRequest $request): JsonResponse
    {
        $tenantId = $request->attributes->get('authenticated_tenant_id');

        if (!is_string($tenantId) || $tenantId === '') {
            return response()->json([
                'message' => 'Tenant context tidak ditemukan.',
            ], 403);
        }

        $authenticatedUserId = auth()->id();

        if (!is_string($authenticatedUserId) || $authenticatedUserId === '') {
            return response()->json([
                'message' => 'Authenticated user tidak ditemukan.',
            ], 401);
        }

        $validated = $request->validated();

        try {
            DB::transaction(function () use (
                $validated,
                $tenantId,
                $authenticatedUserId
            ): void {
                $assessmentSetting = DB::table('assessment_settings')
                    ->where('id', $validated['assessment_setting_id'])
                    ->where('tenant_id', $tenantId)
                    ->first();

                if ($assessmentSetting === null) {
                    throw new HttpException(
                        403,
                        'Assessment setting bukan milik tenant aktif.'
                    );
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
                    throw new HttpException(
                        403,
                        'Satu atau lebih student tidak terdaftar pada tenant aktif.'
                    );
                }

                $now = now();

                $rows = collect($validated['grades'])
                    ->map(
                        static function (array $grade) use (
                            $validated,
                            $tenantId,
                            $authenticatedUserId,
                            $now
                        ): array {
                            return [
                                'id' => (string) Str::uuid(),
                                'tenant_id' => $tenantId,
                                'assessment_setting_id' =>
                                $validated['assessment_setting_id'],
                                'student_id' => $grade['student_id'],

                                /*
                                 * TEMPORARY:
                                 * Saat ini menggunakan authenticated user ID.
                                 *
                                 * Ini BELUM FINAL karena schema:
                                 * student_grades.teacher_id
                                 * -> employees.id
                                 *
                                 * Akan diganti menjadi employee.id setelah
                                 * resolusi User -> Person -> Employee
                                 * diverifikasi dan diimplementasikan.
                                 */
                                'teacher_id' => $authenticatedUserId,

                                'score' => $grade['score'],
                                'notes' => $grade['notes'] ?? null,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    )
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
                    'authenticated_user_id' => $authenticatedUserId,
                    'assessment_setting_id' =>
                    $validated['assessment_setting_id'] ?? null,
                    'exception' => $exception,
                ]
            );

            if ($exception instanceof HttpException) {
                throw $exception;
            }

            return response()->json([
                'message' =>
                'Terjadi kesalahan saat menyimpan nilai student.',
            ], 500);
        }
    }
}
