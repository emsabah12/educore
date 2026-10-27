<?php

namespace Modules\HR\Application\Payroll;

use Illuminate\Support\Facades\DB;
use Modules\HR\Application\Attendance\AttendanceRecap;
use Modules\HR\Contracts\TeachingLoad;

/**
 * Bagian gaji yang berubah tiap bulan (PRD-001 §16.2, HR-5b): honor mengajar (JP), uang per hari hadir,
 * dan potongan presensi. Hanya menghitung; hak akses dicek pemanggil. Bulan berjalan dihitung sampai
 * hari ini (sama dengan rekap presensi HR-4c).
 *
 * Honor JP [OWNER, OD-HR-11]:
 * - penerima = pegawai yang jenis pegawai ATAU status kepegawaian periode terakhirnya dicentang
 *   "menerima honor per JP" (Katalog SDM);
 * - per lembaga kelas: cara hitung, menit per JP, tarif dari aturan `hr.teaching_pay` lembaga itu;
 * - jam mati = baris jadwal mingguan yang berlaku pada akhir rentang; jam hidup = pertemuan terlaksana;
 * - JP per pertemuan/baris = durasi ÷ menit per JP dibulatkan ke bawah [ASUMSI]; yang tidak pas
 *   kelipatan dihitung sebagai peringatan (`irregular`).
 *
 * Potongan presensi: jumlah Alpa/Terlambat/Pulang cepat dari rekap presensi × tarif `hr.attendance_deduction`
 * di lembaga penempatan utama. Hari "Belum diisi" tidak dipotong, hanya dilaporkan.
 *
 * @phpstan-type TeachingLine array{organization_id: string, organization: string, mode: string, mode_label: string, minutes_per_jp: int, sessions: int, jp: int, rate: int, amount: int, irregular: int}
 * @phpstan-type TeachingResult array{eligible: bool, lines: list<TeachingLine>, total: int}
 * @phpstan-type AttendanceResult array{present_days: int, absent: int, late: int, early: int, empty: int, rates: array{absent: int, late: int, early: int}, deduction: int}|null
 */
final class VariablePay
{
    public function __construct(
        private readonly TeachingLoad $load,
        private readonly TeachingPaySetting $teachingPay,
        private readonly AttendanceDeductionSetting $deductions,
        private readonly AttendanceRecap $attendance,
    ) {}

    /**
     * @param  list<string>  $employeeIds
     * @return array<string, TeachingResult>
     */
    public function teaching(string $tenantId, array $employeeIds, string $month): array
    {
        [$start, $until] = AttendanceRecap::range($month);
        $eligible = $this->eligible($tenantId, $employeeIds);
        $weekly = $this->load->weekly($tenantId, $employeeIds, $until);
        $taught = $this->load->taught($tenantId, $employeeIds, $start, $until);

        $organizationIds = [];

        foreach ([$weekly, $taught] as $source) {
            foreach ($source as $byOrganization) {
                foreach (array_keys($byOrganization) as $organizationId) {
                    $organizationIds[$organizationId] = true;
                }
            }
        }

        $names = DB::table('organizations')->whereIn('id', array_keys($organizationIds))->pluck('name', 'id')->all();
        $settings = [];
        $result = [];

        foreach ($employeeIds as $employeeId) {
            $lines = [];
            $total = 0;
            $organizations = array_unique([...array_keys($weekly[$employeeId] ?? []), ...array_keys($taught[$employeeId] ?? [])]);
            sort($organizations);

            foreach ($organizations as $organizationId) {
                $setting = $settings[$organizationId] ??= $this->teachingPay->for($tenantId, $organizationId);
                $sessions = $setting['mode'] === TeachingPaySetting::MODE_ACTUAL
                    ? ($taught[$employeeId][$organizationId] ?? [])
                    : ($weekly[$employeeId][$organizationId] ?? []);

                $jp = 0;
                $irregular = 0;

                foreach ($sessions as $minutes) {
                    $jp += intdiv($minutes, $setting['minutes']);
                    $irregular += $minutes % $setting['minutes'] === 0 ? 0 : 1;
                }

                $amount = isset($eligible[$employeeId]) ? $jp * $setting['rate'] : 0;
                $total += $amount;

                $lines[] = [
                    'organization_id' => $organizationId,
                    'organization' => (string) ($names[$organizationId] ?? '–'),
                    'mode' => $setting['mode'],
                    'mode_label' => TeachingPaySetting::modeLabel($setting['mode']),
                    'minutes_per_jp' => $setting['minutes'],
                    'sessions' => count($sessions),
                    'jp' => $jp,
                    'rate' => $setting['rate'],
                    'amount' => $amount,
                    'irregular' => $irregular,
                ];
            }

            $result[$employeeId] = ['eligible' => isset($eligible[$employeeId]), 'lines' => $lines, 'total' => $total];
        }

        return $result;
    }

    /**
     * Rekap presensi bulan itu + potongannya; null bila pegawai tanpa penempatan utama.
     *
     * @return AttendanceResult
     */
    public function attendance(string $tenantId, string $employeeId, string $month): ?array
    {
        $summary = $this->attendance->forEmployee($tenantId, $employeeId, $month);
        $organizationId = DB::table('hr_placements')
            ->where('employee_id', $employeeId)
            ->where('is_primary', true)
            ->whereNull('end_date')
            ->value('organization_id');

        if ($summary === null || ! is_string($organizationId)) {
            return null;
        }

        $counts = $summary['counts'];
        $rates = $this->deductions->for($tenantId, $organizationId);

        return [
            'present_days' => $counts['present'] + $counts['duty'],
            'absent' => $counts['absent'],
            'late' => $counts['late'],
            'early' => $counts['early'],
            'empty' => $counts['empty'],
            'rates' => $rates,
            'deduction' => $counts['absent'] * $rates['absent'] + $counts['late'] * $rates['late'] + $counts['early'] * $rates['early'],
        ];
    }

    /**
     * Pegawai penerima honor JP: jenis pegawai atau status kepegawaian periode terakhirnya dicentang.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, true>
     */
    private function eligible(string $tenantId, array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $rows = DB::table('hr_employment_periods as pe')
            ->leftJoin('hr_employment_types as t', 't.id', '=', 'pe.employment_type_id')
            ->leftJoin('hr_employment_classifications as c', 'c.id', '=', 'pe.classification_id')
            ->where('pe.tenant_id', $tenantId)
            ->whereIn('pe.employee_id', $employeeIds)
            // Periode terakhir: yang masih berjalan, atau yang paling akhir dimulai.
            ->orderByRaw('pe.end_date IS NOT NULL')
            ->orderByDesc('pe.start_date')
            ->get(['pe.employee_id', 't.receives_teaching_pay as by_type', 'c.receives_teaching_pay as by_classification']);

        $seen = [];
        $eligible = [];

        foreach ($rows as $row) {
            $employeeId = (string) $row->employee_id;

            if (isset($seen[$employeeId])) {
                continue;
            }

            $seen[$employeeId] = true;

            if ((bool) $row->by_type || (bool) $row->by_classification) {
                $eligible[$employeeId] = true;
            }
        }

        return $eligible;
    }
}
