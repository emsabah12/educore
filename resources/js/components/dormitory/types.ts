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
    occupancy: Occupancy;
    over_capacity: boolean;
};

export type Occupancy = {
    active: number;
    planned: number;
    residents: number;
    supervisors: number;
    unusable: number;
};

export type PlacementRow = {
    id: string;
    membership_id: string;
    name: string;
    detail: string;
    category: 'PENGHUNI' | 'PENGAWAS';
    category_label: string;
    status: 'DIRENCANAKAN' | 'AKTIF' | 'SELESAI' | 'DIBATALKAN';
    status_label: string;
    planned_on: string | null;
    check_in_on: string | null;
    check_out_on: string | null;
    end_reason_label: string | null;
    cancel_reason: string | null;
    note: string | null;
    bed: string | null;
    bed_id: string | null;
    locker: string | null;
    locker_id: string | null;
    resource_problem: boolean;
    /** Santri sudah tidak terdaftar di lembaga milik asrama (DM-5a). */
    needs_review: boolean;
    dormitory: string;
    dormitory_id: string;
    building: string;
    room: string;
    room_id: string;
};

export type PlacementPage = {
    data: PlacementRow[];
    total: number;
    from: number | null;
    to: number | null;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type ResidentFilters = {
    dormitory: string | null;
    building: string | null;
    status: 'semua' | 'aktif' | 'rencana';
    category: string | null;
    q: string;
    review: boolean;
};

/** "12 dari 16 terisi · 2 rencana" */
export function occupancyText(occupancy: Occupancy, capacity: Capacity) {
    const held = occupancy.active + occupancy.planned;
    const base = `${held} dari ${capacity.effective} terisi`;

    return occupancy.planned > 0
        ? `${base} (${occupancy.planned} rencana)`
        : base;
}

/** Tanggal "2026-11-15" → "15 Nov 2026". */
export function dateText(value: string | null) {
    if (!value) {
        return '–';
    }

    const [year, month, day] = value.split('-').map(Number);
    const months = [
        'Jan',
        'Feb',
        'Mar',
        'Apr',
        'Mei',
        'Jun',
        'Jul',
        'Agu',
        'Sep',
        'Okt',
        'Nov',
        'Des',
    ];

    return `${day} ${months[month - 1]} ${year}`;
}

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

/** Penugasan wali kamar (DM-3). */
export type SupervisorRow = {
    id: string;
    membership_id: string;
    name: string;
    start_on: string;
    end_on: string | null;
    note: string | null;
    lives_here: boolean;
    employee_active: boolean;
};
