/**
 * Tipe & bantuan tampilan presensi mengajar (PRD-002 §20, AC-6).
 */
export type TeachingPerson = { id: string; name: string };

export type TeachingRecord = {
    status: 'MENGAJAR' | 'DIGANTIKAN' | 'KOSONG';
    status_label: string;
    substitute: TeachingPerson | null;
    reason: string | null;
    reason_label: string | null;
    note: string | null;
    source: 'GURU' | 'PETUGAS' | 'OTOMATIS';
    recorded_by: string | null;
};

export type TeachingLesson = {
    key: string;
    entry_id: string | null;
    date: string;
    start: string;
    end: string;
    class_id: string;
    class_name: string;
    organization_id: string;
    organization: string;
    subject: string;
    teacher: TeachingPerson | null;
    record: TeachingRecord | null;
};

export const TEACHING_STATUS_CLASS: Record<TeachingRecord['status'], string> = {
    MENGAJAR:
        'border-emerald-500/50 bg-emerald-50 text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100',
    DIGANTIKAN:
        'border-sky-500/50 bg-sky-50 text-sky-900 dark:bg-sky-950/40 dark:text-sky-100',
    KOSONG: 'border-red-500/50 bg-red-50 text-red-900 dark:bg-red-950/40 dark:text-red-100',
};

/** "07:00" → "07.00". */
export function displayTime(value: string): string {
    return value.replace(':', '.');
}

const dateFormatter = new Intl.DateTimeFormat('id-ID', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

export function formatTeachingDate(value: string): string {
    return dateFormatter.format(new Date(`${value}T00:00:00Z`));
}

/** Ringkasan satu catatan, mis. "Digantikan Bu Ani · Sakit". */
export function describeRecord(record: TeachingRecord): string {
    return [
        record.status === 'DIGANTIKAN' && record.substitute
            ? `Digantikan ${record.substitute.name}`
            : record.status_label,
        record.reason_label,
    ]
        .filter(Boolean)
        .join(' · ');
}
