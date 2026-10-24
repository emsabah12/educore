/**
 * Ringkasan presensi pegawai sebulan (PRD-001 §14.4, HR-4c): dipakai Presensi saya, detail pegawai,
 * dan rekap lembaga.
 */
export type AttendanceCounts = {
    required: number;
    present: number;
    late: number;
    late_minutes: number;
    early: number;
    early_minutes: number;
    sick: number;
    permit: number;
    duty: number;
    leave: number;
    absent: number;
    empty: number;
};

export type AttendanceSummaryData = {
    month: string;
    label: string;
    until: string;
    counts: AttendanceCounts;
};

/** Kolom rekap, urut tampil. */
export const ATTENDANCE_COLUMNS: {
    key: keyof AttendanceCounts;
    label: string;
}[] = [
    { key: 'required', label: 'Hari wajib' },
    { key: 'present', label: 'Hadir' },
    { key: 'late', label: 'Terlambat' },
    { key: 'early', label: 'Pulang cepat' },
    { key: 'sick', label: 'Sakit' },
    { key: 'permit', label: 'Izin' },
    { key: 'duty', label: 'Dinas luar' },
    { key: 'leave', label: 'Cuti' },
    { key: 'absent', label: 'Alpa' },
    { key: 'empty', label: 'Belum diisi' },
];

/** Isi sel: terlambat & pulang cepat menampilkan kali + total menit. */
export function attendanceValue(
    counts: AttendanceCounts,
    key: keyof AttendanceCounts,
): string {
    if (key === 'late' && counts.late > 0) {
        return `${counts.late}× (${counts.late_minutes} mnt)`;
    }

    if (key === 'early' && counts.early > 0) {
        return `${counts.early}× (${counts.early_minutes} mnt)`;
    }

    return String(counts[key]);
}

export function AttendanceSummary({
    summary,
}: {
    summary: AttendanceSummaryData;
}) {
    return (
        <dl className="grid grid-cols-[repeat(auto-fit,minmax(7rem,1fr))] gap-3">
            {ATTENDANCE_COLUMNS.map((column) => (
                <div key={column.key} className="min-w-0 space-y-0.5">
                    <dt className="text-xs text-muted-foreground">
                        {column.label}
                    </dt>
                    <dd className="text-sm font-medium tabular-nums">
                        {attendanceValue(summary.counts, column.key)}
                    </dd>
                </div>
            ))}
        </dl>
    );
}
