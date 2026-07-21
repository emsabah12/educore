<?php

namespace Modules\Academic\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Exception;

class ReportCardAggregationService
{
    /**
     * Mengagregasikan nilai mentah santri menjadi nilai akhir rapor per periode akademik.
     *
     * @param string $tenantId
     * @param string $academicPeriodId
     * @param string $santriId
     * @param string $academicClassId
     * @param array $attendanceData
     * @param string|null $teacherNotes
     * @return string ID dari AcademicReportCard yang dibuat/diperbarui
     * @throws InvalidArgumentException|Exception
     */
    public function aggregateForSantri(
        string $tenantId,
        string $academicPeriodId,
        string $santriId,
        string $academicClassId,
        array $attendanceData = [],
        ?string $teacherNotes = null
    ): string {
        // 1. Validasi Input Dasar
        if (empty($tenantId) || empty($academicPeriodId) || empty($santriId) || empty($academicClassId)) {
            throw new InvalidArgumentException("Parameter tenant, periode, santri, dan kelas wajib diisi.");
        }

        return DB::transaction(function () use ($tenantId, $academicPeriodId, $santriId, $academicClassId, $attendanceData, $teacherNotes) {

            // 2. Ambil semua setting komponen penilaian (bobot) untuk periode ini
            $assessmentSettings = DB::table('assessment_settings')
                ->where('tenant_id', $tenantId)
                ->where('academic_period_id', $academicPeriodId)
                ->get()
                ->groupBy('academic_subject_id');

            if ($assessmentSettings->isEmpty()) {
                throw new Exception("Tidak ditemukan pengaturan bobot penilaian (assessment_settings) untuk periode akademik ini.");
            }

            // 3. Ambil semua nilai mentah milik santri pada periode ini
            $grades = DB::table('student_grades')
                ->where('tenant_id', $tenantId)
                ->where('santri_id', $santriId)
                ->get()
                ->groupBy('assessment_setting_id');

            // 4. Buat atau perbarui header Rapor (Upsert secara aman)
            $reportCardId = DB::table('academic_report_cards')
                ->where('tenant_id', $tenantId)
                ->where('academic_period_id', $academicPeriodId)
                ->where('santri_id', $santriId)
                ->value('id');

            if (!$reportCardId) {
                $reportCardId = Str::uuid()->toString();
                DB::table('academic_report_cards')->insert([
                    'id' => $reportCardId,
                    'tenant_id' => $tenantId,
                    'academic_period_id' => $academicPeriodId,
                    'santri_id' => $santriId,
                    'academic_class_id' => $academicClassId,
                    'attendance_sick' => $attendanceData['sick'] ?? 0,
                    'attendance_permission' => $attendanceData['permission'] ?? 0,
                    'attendance_absent' => $attendanceData['absent'] ?? 0,
                    'teacher_notes' => $teacherNotes,
                    'status' => 'draft',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                // Jika status sudah locked, tidak boleh di-update lewat agregasi reguler
                $currentStatus = DB::table('academic_report_cards')->where('id', $reportCardId)->value('status');
                if ($currentStatus === 'locked' || $currentStatus === 'published') {
                    throw new Exception("Gagal mengagregasi nilai: Rapor santri sudah dikunci (locked/published).");
                }

                DB::table('academic_report_cards')
                    ->where('id', $reportCardId)
                    ->update([
                        'academic_class_id' => $academicClassId,
                        'attendance_sick' => $attendanceData['sick'] ?? 0,
                        'attendance_permission' => $attendanceData['permission'] ?? 0,
                        'attendance_absent' => $attendanceData['absent'] ?? 0,
                        'teacher_notes' => $teacherNotes,
                        'updated_at' => now(),
                    ]);
            }

            // Hapus detail rapor lama untuk menghindari inkonsistensi data sebelum re-kalkulasi
            DB::table('academic_report_details')->where('academic_report_card_id', $reportCardId)->delete();

            // 5. Looping Kalkulasi Per Mata Pelajaran
            foreach ($assessmentSettings as $subjectId => $settings) {
                $totalWeightedScore = 0;
                $totalWeightAllocated = 0;

                foreach ($settings as $setting) {
                    $totalWeightAllocated += $setting->weight;

                    // Ambil nilai santri untuk komponen ini (jika tidak ada, anggap 0)
                    $studentGrade = $grades->get($setting->id)?->first();
                    $score = $studentGrade ? (float) $studentGrade->score : 0.0;

                    $totalWeightedScore += ($score * ($setting->weight / 100));
                }

                // Normalisasi jika total bobot komponen belum genap 100%
                if ($totalWeightAllocated > 0 && $totalWeightAllocated < 100) {
                    $finalScore = ($totalWeightedScore / ($totalWeightAllocated / 100));
                } else {
                    $finalScore = $totalWeightedScore;
                }

                // Pastikan nilai berada di range 0 - 100
                $finalScore = max(0, min(100, round($finalScore, 2)));

                // Tentukan Huruf Mutu & Predikat Catatan Singkat
                $letterGrade = $this->calculateLetterGrade($finalScore);
                $predicateNotes = $this->generatePredicateNotes($letterGrade, $subjectId);

                // Insert Detail Rapor Baru
                DB::table('academic_report_details')->insert([
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $tenantId,
                    'academic_report_card_id' => $reportCardId,
                    'academic_subject_id' => $subjectId,
                    'final_score' => $finalScore,
                    'letter_grade' => $letterGrade,
                    'predicate_notes' => $predicateNotes,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            Log::info("Agregasi rapor berhasil dijalankan untuk Santri ID: {$santriId} pada Tenant: {$tenantId}");

            return $reportCardId;
        });
    }

    /**
     * Hitung Nilai Huruf Mutu secara internal.
     */
    private function calculateLetterGrade(float $score): string
    {
        if ($score >= 85.0) return 'A';
        if ($score >= 75.0) return 'B';
        if ($score >= 60.0) return 'C';
        if ($score >= 45.0) return 'D';
        return 'E';
    }

    /**
     * Hasilkan catatan kompetensi dinamis berdasarkan nilai huruf.
     */
    private function generatePredicateNotes(string $letterGrade, string $subjectId): string
    {
        return match ($letterGrade) {
            'A' => "Menunjukkan penguasaan materi yang sangat cemerlang dan istimewa.",
            'B' => "Menunjukkan kemampuan yang baik dan tuntas dalam memahami materi.",
            'C' => "Cukup memahami materi, disarankan meningkatkan konsistensi belajar.",
            'D' => "Kurang menguasai materi, memerlukan bimbingan intensif tambahan.",
            default => "Belum memenuhi standar ketuntasan minimal, wajib mengikuti remedial.",
        };
    }
}
