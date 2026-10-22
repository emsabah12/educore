/**
 * Bantuan tampilan cuti bersama (Kelola cuti, HR-3d).
 */
export type LeaveStatus =
    | 'MENUNGGU'
    | 'DISETUJUI'
    | 'DITOLAK'
    | 'DITARIK'
    | 'DIBATALKAN';

export const LEAVE_STATUS_CLASS: Record<LeaveStatus, string> = {
    MENUNGGU:
        'border-amber-500/50 bg-amber-50 text-amber-900 dark:bg-amber-950/40 dark:text-amber-100',
    DISETUJUI:
        'border-emerald-500/50 bg-emerald-50 text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100',
    DITOLAK:
        'border-red-500/50 bg-red-50 text-red-900 dark:bg-red-950/40 dark:text-red-100',
    DITARIK: 'text-muted-foreground',
    DIBATALKAN: 'text-muted-foreground',
};

const dateFormatter = new Intl.DateTimeFormat('id-ID', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    timeZone: 'UTC',
});

export function formatLeaveDate(value: string): string {
    return dateFormatter.format(new Date(`${value}T00:00:00Z`));
}

export function formatLeaveRange(start: string, end: string): string {
    return start === end
        ? formatLeaveDate(start)
        : `${formatLeaveDate(start)} – ${formatLeaveDate(end)}`;
}

const dateTimeFormatter = new Intl.DateTimeFormat('id-ID', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});

export function formatLeaveDateTime(value: string): string {
    return dateTimeFormatter.format(new Date(value));
}
