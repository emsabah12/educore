/*
 * Tipe & format bersama gaji pegawai (PRD-001 §16, HR-5).
 */

export type PayRow = {
    component_id: string;
    code: string;
    name: string;
    kind: 'PENDAPATAN' | 'POTONGAN';
    kind_label: string;
    basis: 'TETAP' | 'PER_HARI_HADIR';
    basis_label: string;
    is_active: boolean;
    amount: number | null;
};

export type PositionLine = {
    component_id: string;
    component: string;
    kind: 'PENDAPATAN' | 'POTONGAN';
    position: string;
    organization: string;
    amount: number;
    /** Tarif lembaga itu, atau tarif semua lembaga. */
    scope: 'LEMBAGA' | 'SEMUA';
};

export type PlacementRef = {
    position: string;
    organization: string;
    is_primary: boolean;
};

export type PaySummary = {
    rows: PayRow[];
    placements: PlacementRef[];
    positions: PositionLine[];
    monthly_earning: number;
    monthly_deduction: number;
    per_present_day: number;
    configured: boolean;
};

const rupiah = new Intl.NumberFormat('id-ID');

export function formatNumber(value: number): string {
    return rupiah.format(value);
}

export function formatRupiah(value: number): string {
    return `Rp ${rupiah.format(value)}`;
}
