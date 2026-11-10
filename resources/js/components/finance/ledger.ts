import { selectClass } from '@/components/native-select';
/**
 * Bantuan bersama halaman pembukuan (PRD-007, PB-1).
 */
export type Option = { value: string; label: string };

export type LedgerSettings = {
    active: boolean;
    start_date: string | null;
    fiscal_start_month: number;
    needs_rebuild: boolean;
    rebuilding: boolean;
    rebuild_done: number;
    rebuild_total: number;
    rebuilt_at: string | null;
};

const rupiah = new Intl.NumberFormat('id-ID');

export const formatRupiah = (amount: number) => `Rp ${rupiah.format(amount)}`;

export const formatNumber = (amount: number) => rupiah.format(amount);

const dateFormat = new Intl.DateTimeFormat('id-ID', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});

/** "2026-11-05" → "5 Nov 2026" (tanpa geser zona waktu). */
export function formatDate(value: string): string {
    const [year, month, day] = value.split('-').map(Number);

    return dateFormat.format(new Date(year, month - 1, day));
}

const monthFormat = new Intl.DateTimeFormat('id-ID', {
    month: 'long',
    year: 'numeric',
});

/** "2026-11-01" → "November 2026". */
export function formatMonth(value: string): string {
    const [year, month] = value.split('-').map(Number);

    return monthFormat.format(new Date(year, month - 1, 1));
}

export type LedgerPeriod = {
    from: string;
    to: string;
    fiscal_start: string;
    fiscal_end: string;
    start_date: string;
    clamped: boolean;
};

export type PeriodPreset = { label: string; from: string; to: string };

/**
 * Saldo bertanda (+ = debit) ditampilkan menurut saldo normal akun: positif = saldo normal, negatif dalam kurung.
 */
export function formatBalance(
    signed: number,
    normal: 'DEBIT' | 'KREDIT' | string,
): string {
    const value = normal === 'DEBIT' ? signed : -signed;

    return value < 0 ? `(${formatNumber(-value)})` : formatNumber(value);
}

export { selectClass };
