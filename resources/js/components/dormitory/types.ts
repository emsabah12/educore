/** Tipe data halaman asrama (PRD-008 §15 DM-1); bentuknya sama dengan FacilityDirectory di server. */

export type Option = { value: string; label: string };

export type Capacity = {
    beds_total: number;
    beds_available: number;
    lockers_total: number;
    lockers_available: number;
    effective: number;
    supervisor_slots: number;
    resident_slots: number;
};

export type CapacityBasis = 'BED' | 'LOKER' | 'BED_LOKER';

export type Composition = 'KHUSUS_SANTRI' | 'SANTRI_PENGAWAS';

export type YearEndPolicy = 'TETAP' | 'KOSONGKAN';

export type RoomRow = {
    id: string;
    building_id: string;
    code: string;
    name: string | null;
    capacity_basis: CapacityBasis;
    capacity_basis_label: string;
    composition: Composition;
    composition_label: string;
    supervisor_slots: number;
    year_end_policy: YearEndPolicy;
    is_active: boolean;
    capacity: Capacity;
};

export type ResourceRow = {
    id: string;
    code: string;
    status: 'TERSEDIA' | 'TIDAK_TERSEDIA';
    status_label: string;
    unavailable_reason: string | null;
    unavailable_reason_label: string | null;
    unavailable_note: string | null;
    is_active: boolean;
};

export type RoomOptions = {
    bases: Option[];
    compositions: Option[];
    policies: Option[];
    supervisorSlotsMax: number;
};

/** "Kapasitas 16 · 15 santri + 1 pengawas" — ringkas untuk daftar kamar. */
export function capacityText(capacity: Capacity, composition: Composition) {
    if (capacity.effective === 0) {
        return 'Belum ada kapasitas';
    }

    if (composition === 'KHUSUS_SANTRI') {
        return `Kapasitas ${capacity.effective} santri`;
    }

    return `Kapasitas ${capacity.effective} · ${capacity.resident_slots} santri + ${Math.min(capacity.supervisor_slots, capacity.effective)} pengawas`;
}
