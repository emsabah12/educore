import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRightLeft,
    BedDouble,
    ClipboardCheck,
    LogIn,
    LogOut,
    Pencil,
    Plus,
    Trash2,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmAction } from '@/components/confirm-action';
import {
    Empty,
    FacilityDialog,
    Field,
    ResourceFields,
    RoomFields,
    residentsStayNote,
    StatusToggle,
} from '@/components/dormitory/facility-forms';
import type { OpenMutations } from '@/components/dormitory/mutation-forms';
import { MutationDialog } from '@/components/dormitory/mutation-forms';
import type { RoomResources } from '@/components/dormitory/placement-forms';
import {
    CancelPlanAction,
    ChangeResourcesDialog,
    CheckInDialog,
    CheckOutDialog,
    PlaceDialog,
} from '@/components/dormitory/placement-forms';
import {
    SupervisorHistory,
    SupervisorSection,
} from '@/components/dormitory/supervisors';
import {
    Pagination,
    PlacementStatusBadge,
    ResourceWarning,
    dateLine,
    resourceText,
} from '@/components/dormitory/residents';
import type {
    Capacity,
    Occupancy,
    Option,
    PlacementPage,
    PlacementRow,
    ResourceRow,
    RoomOptions,
    RoomRow,
    SupervisorRow,
} from '@/components/dormitory/types';
import Heading from '@/components/heading';
import { PageTabs, usePageTab } from '@/components/page-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Kind = 'bed' | 'loker';

type Holder = { name: string; status: string };

type Props = {
    room: Omit<
        RoomRow,
        'building_id' | 'capacity' | 'occupancy' | 'over_capacity'
    > & {
        accepts_changes: boolean;
        building: {
            id: string;
            code: string;
            name: string;
            gender: 'L' | 'P';
            gender_label: string;
            is_active: boolean;
        };
        dormitory: {
            id: string;
            code: string;
            name: string;
            organization: string;
            is_active: boolean;
        };
    };
    capacity: Capacity;
    occupancy: Occupancy;
    over_capacity: boolean;
    beds: ResourceRow[];
    lockers: ResourceRow[];
    canManage: boolean;
    canViewResidents: boolean;
    canManageResidents: boolean;
    canFillAttendance: boolean;
    holders: PlacementRow[];
    history: PlacementPage | null;
    supervisors: SupervisorRow[];
    supervisorHistory: SupervisorRow[] | null;
    supervisorMax: number;
    canRequestMutation: boolean;
    canEmergencyMutation: boolean;
    openMutations: OpenMutations;
    resourceHolders: {
        beds: Record<string, Holder>;
        lockers: Record<string, Holder>;
    };
    suggestion: { bed: string | null; locker: string | null };
    today: string;
    options: RoomOptions & {
        reasons: Option[];
        batchMax: number;
        noteMax: number;
        categories: Option[];
        endReasons: Option[];
        emergencyCategories: Option[];
    };
};

type OpenDialog =
    | { kind: 'edit-room' }
    | { kind: 'add'; resource: Kind }
    | { kind: 'edit'; resource: Kind; row: ResourceRow }
    | { kind: 'place' }
    | { kind: 'check-in'; row: PlacementRow }
    | { kind: 'check-out'; row: PlacementRow }
    | { kind: 'change'; row: PlacementRow }
    | { kind: 'mutation'; row: PlacementRow };

const LABEL: Record<Kind, string> = { bed: 'Bed', loker: 'Loker' };

const TABS = ['penghuni', 'bed-loker', 'riwayat'] as const;
type Tab = (typeof TABS)[number];

/**
 * Detail kamar (PRD-008 §4.3–§4.5, §16): ringkasan kapasitas & keterisian, penghuni (tempatkan, check-in, check-out,
 * ganti bed/loker, batalkan rencana), inventaris bed & loker, dan riwayat kamar. Kapasitas dihitung server dari
 * bed/loker yang tersedia; semua aturan penempatan diperiksa server.
 */
export default function RoomShow({
    room,
    capacity,
    occupancy,
    over_capacity: overCapacity,
    beds,
    lockers,
    canManage,
    canViewResidents,
    canManageResidents,
    canFillAttendance,
    holders,
    history,
    supervisors,
    supervisorHistory,
    supervisorMax,
    canRequestMutation,
    canEmergencyMutation,
    openMutations,
    resourceHolders,
    suggestion,
    today,
    options,
}: Props) {
    const tabs = canViewResidents ? TABS : (['bed-loker'] as const);
    const [tab, setTabState] = usePageTab<Tab>(
        tabs,
        canViewResidents ? 'penghuni' : 'bed-loker',
    );
    const [dialog, setDialog] = useState<OpenDialog | null>(null);
    const close = () => setDialog(null);
    const counts = (kind: Kind) =>
        kind === 'bed'
            ? room.capacity_basis !== 'LOKER'
            : room.capacity_basis !== 'BED';
    const editableRoom: RoomRow = {
        ...room,
        building_id: room.building.id,
        capacity,
        occupancy,
        over_capacity: overCapacity,
    };
    const title = `Kamar ${room.code}${room.name ? ` · ${room.name}` : ''}`;
    const heldBy: Record<string, string> = {};

    for (const holder of holders) {
        if (holder.bed_id) {
            heldBy[holder.bed_id] = holder.id;
        }

        if (holder.locker_id) {
            heldBy[holder.locker_id] = holder.id;
        }
    }

    const resources: RoomResources = {
        basis: room.capacity_basis as RoomResources['basis'],
        beds,
        lockers,
        heldBy,
        suggestion,
    };
    const held = occupancy.active + occupancy.planned;
    const canPlace =
        canManageResidents &&
        room.accepts_changes &&
        room.building.is_active &&
        room.dormitory.is_active;

    const setTab = (value: Tab) => {
        setTabState(value);

        // Riwayat dimuat saat tab dibuka pertama kali.
        if (value === 'riwayat' && history === null) {
            router.reload({
                only: ['history', 'supervisorHistory'],
                data: { tab: 'riwayat' },
            });
        }
    };

    return (
        <>
            <Head title={title} />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <div>
                    <Link
                        href={`/asrama/${room.dormitory.id}`}
                        className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        {room.dormitory.name}
                    </Link>
                </div>

                <div className="space-y-2">
                    <Heading
                        title={title}
                        description={`${room.building.name} (${room.building.gender_label}) · ${room.dormitory.name} · ${room.dormitory.organization}`}
                    />
                    <div className="-mt-6 flex flex-wrap items-center gap-2">
                        <Badge variant="secondary">
                            {room.composition_label}
                        </Badge>
                        <Badge variant="secondary">
                            Dasar {room.capacity_basis_label.toLowerCase()}
                        </Badge>
                        {!room.is_active && (
                            <Badge variant="outline">Nonaktif</Badge>
                        )}
                        {canFillAttendance && (
                            <Button size="sm" variant="outline" asChild>
                                <Link href={`/asrama/kamar/${room.id}/absen`}>
                                    <ClipboardCheck />
                                    Absen
                                </Link>
                            </Button>
                        )}
                        {canManage && (
                            <>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        setDialog({ kind: 'edit-room' })
                                    }
                                >
                                    <Pencil />
                                    Ubah kamar
                                </Button>
                                <StatusToggle
                                    active={room.is_active}
                                    action={`/asrama/kamar/${room.id}/status`}
                                    subject={`kamar ${room.code}`}
                                    deactivateNote={
                                        'Kamar ini tidak menerima penghuni baru. Data dan riwayat tetap tersimpan.' +
                                        residentsStayNote(
                                            occupancy.active,
                                            occupancy.planned,
                                        )
                                    }
                                    errorBag="roomStatus"
                                />
                            </>
                        )}
                    </div>
                </div>

                <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <Stat
                        label="Terisi"
                        value={`${held}/${capacity.effective}`}
                        alert={overCapacity}
                    />
                    <Stat
                        label="Santri"
                        value={`${occupancy.residents}/${capacity.resident_slots}`}
                    />
                    <Stat
                        label="Bed tersedia"
                        value={`${capacity.beds_available}/${capacity.beds_total}`}
                    />
                    <Stat
                        label="Loker tersedia"
                        value={`${capacity.lockers_available}/${capacity.lockers_total}`}
                    />
                </dl>
                <p className="-mt-1 text-xs text-muted-foreground">
                    {room.capacity_basis === 'BED_LOKER'
                        ? 'Setiap penghuni butuh satu bed dan satu loker, jadi kapasitas = jumlah yang paling sedikit.'
                        : `Kapasitas = jumlah ${room.capacity_basis === 'BED' ? 'bed' : 'loker'} tersedia.`}
                    {room.composition === 'SANTRI_PENGAWAS' &&
                        ` ${capacity.supervisor_slots} slot disediakan untuk pengawas (terisi ${occupancy.supervisors}).`}
                    {occupancy.planned > 0 &&
                        ` Terisi termasuk ${occupancy.planned} rencana.`}
                </p>
                {overCapacity && (
                    <p className="flex items-start gap-2 rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive">
                        <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                        Kamar kelebihan muatan: penghuni & rencana melebihi
                        kapasitas (biasanya karena bed/loker rusak). Tidak bisa
                        menerima penempatan baru sampai beres.
                    </p>
                )}

                {canViewResidents && (
                    <PageTabs
                        label="Bagian kamar"
                        tabs={[
                            {
                                value: 'penghuni',
                                label: 'Penghuni',
                                badge: holders.length,
                            },
                            { value: 'bed-loker', label: 'Bed & loker' },
                            { value: 'riwayat', label: 'Riwayat' },
                        ]}
                        value={tab}
                        onChange={setTab}
                    />
                )}

                {(tab === 'penghuni' || !canViewResidents) && (
                    <SupervisorSection
                        roomId={room.id}
                        roomCode={room.code}
                        gender={room.building.gender}
                        supervisors={supervisors}
                        max={supervisorMax}
                        canManage={canManage}
                        acceptsChanges={room.accepts_changes}
                        today={today}
                        noteMax={options.noteMax}
                    />
                )}

                {tab === 'penghuni' && canViewResidents && (
                    <HolderSection
                        holders={holders}
                        canManage={canManageResidents}
                        canPlace={canPlace}
                        noteMax={options.noteMax}
                        onPlace={() => setDialog({ kind: 'place' })}
                        onCheckIn={(row) =>
                            setDialog({ kind: 'check-in', row })
                        }
                        onCheckOut={(row) =>
                            setDialog({ kind: 'check-out', row })
                        }
                        onChange={(row) => setDialog({ kind: 'change', row })}
                        canMutate={canRequestMutation || canEmergencyMutation}
                        openMutations={openMutations}
                        onMutate={(row) => setDialog({ kind: 'mutation', row })}
                    />
                )}

                {tab === 'bed-loker' &&
                    (['bed', 'loker'] as const).map((kind) => (
                        <ResourceSection
                            key={kind}
                            kind={kind}
                            rows={kind === 'bed' ? beds : lockers}
                            holders={
                                kind === 'bed'
                                    ? resourceHolders.beds
                                    : resourceHolders.lockers
                            }
                            counted={counts(kind)}
                            canManage={canManage}
                            acceptsChanges={room.accepts_changes}
                            onAdd={() =>
                                setDialog({ kind: 'add', resource: kind })
                            }
                            onEdit={(row) =>
                                setDialog({ kind: 'edit', resource: kind, row })
                            }
                        />
                    ))}

                {tab === 'riwayat' &&
                    canViewResidents &&
                    (history === null ? (
                        <p className="text-sm text-muted-foreground">
                            Memuat riwayat…
                        </p>
                    ) : (
                        <>
                            <HistorySection page={history} />
                            <SupervisorHistory rows={supervisorHistory ?? []} />
                        </>
                    ))}
            </div>

            {dialog?.kind === 'place' && (
                <PlaceDialog
                    open
                    onClose={close}
                    roomId={room.id}
                    roomCode={room.code}
                    allowSupervisor={room.composition === 'SANTRI_PENGAWAS'}
                    categories={options.categories}
                    resources={resources}
                    today={today}
                    noteMax={options.noteMax}
                />
            )}
            {dialog?.kind === 'check-in' && (
                <CheckInDialog
                    row={dialog.row}
                    onClose={close}
                    resources={resources}
                    today={today}
                />
            )}
            {dialog?.kind === 'check-out' && (
                <CheckOutDialog
                    row={dialog.row}
                    onClose={close}
                    endReasons={options.endReasons}
                    today={today}
                    noteMax={options.noteMax}
                />
            )}
            {dialog?.kind === 'mutation' && (
                <MutationDialog
                    row={dialog.row}
                    onClose={close}
                    canRequest={canRequestMutation}
                    canEmergency={canEmergencyMutation}
                    emergencyCategories={options.emergencyCategories}
                    hasOpenRequest={openMutations[dialog.row.id] !== undefined}
                />
            )}
            {dialog?.kind === 'change' && (
                <ChangeResourcesDialog
                    row={dialog.row}
                    onClose={close}
                    resources={resources}
                />
            )}

            <FacilityDialog
                open={dialog?.kind === 'edit-room'}
                onClose={close}
                title={`Ubah kamar ${room.code}`}
                action={`/asrama/kamar/${room.id}`}
                method="patch"
                errorBag="editRoom"
                submitLabel="Simpan kamar"
            >
                {(errors) => (
                    <RoomFields
                        prefix="dm-room-edit"
                        errors={errors}
                        options={options}
                        room={editableRoom}
                    />
                )}
            </FacilityDialog>

            {dialog?.kind === 'add' && (
                <FacilityDialog
                    open
                    onClose={close}
                    title={`Tambah ${LABEL[dialog.resource].toLowerCase()}`}
                    description={
                        dialog.resource === 'loker'
                            ? 'Satu pintu/kompartemen lemari yang dipakai satu penghuni = satu loker.'
                            : 'Kode dibuat berurutan dari awalan + nomor.'
                    }
                    action={`/asrama/kamar/${room.id}/bed-loker`}
                    errorBag="addResources"
                    submitLabel="Tambahkan"
                >
                    {(errors) => (
                        <AddResourceFields
                            kind={dialog.resource}
                            roomCode={room.code}
                            batchMax={options.batchMax}
                            errors={errors}
                            next={
                                (dialog.resource === 'bed' ? beds : lockers)
                                    .length + 1
                            }
                        />
                    )}
                </FacilityDialog>
            )}

            {dialog?.kind === 'edit' && (
                <FacilityDialog
                    open
                    onClose={close}
                    title={`Ubah ${LABEL[dialog.resource].toLowerCase()} ${dialog.row.code}`}
                    action={`/asrama/${dialog.resource}/${dialog.row.id}`}
                    method="patch"
                    errorBag="editResource"
                    submitLabel="Simpan"
                    footer={
                        <ResourceExtraActions
                            kind={dialog.resource}
                            row={dialog.row}
                            acceptsChanges={room.accepts_changes}
                            onDone={close}
                        />
                    }
                >
                    {(errors) => (
                        <ResourceFields
                            prefix="dm-resource"
                            errors={errors}
                            resource={dialog.row}
                            reasons={options.reasons}
                            noteMax={options.noteMax}
                        />
                    )}
                </FacilityDialog>
            )}
        </>
    );
}

/** Penghuni & rencana kamar ini, dengan aksi per baris. */
function HolderSection({
    holders,
    canManage,
    canPlace,
    noteMax,
    onPlace,
    onCheckIn,
    onCheckOut,
    onChange,
    canMutate,
    openMutations,
    onMutate,
}: {
    holders: PlacementRow[];
    canManage: boolean;
    canPlace: boolean;
    noteMax: number;
    onPlace: () => void;
    onCheckIn: (row: PlacementRow) => void;
    onCheckOut: (row: PlacementRow) => void;
    onChange: (row: PlacementRow) => void;
    canMutate: boolean;
    openMutations: OpenMutations;
    onMutate: (row: PlacementRow) => void;
}) {
    return (
        <section className="space-y-3">
            {canPlace && (
                <div className="flex justify-end">
                    <Button type="button" size="sm" onClick={onPlace}>
                        <Plus />
                        Tempatkan
                    </Button>
                </div>
            )}

            {holders.length === 0 ? (
                <Empty>
                    Kamar ini masih kosong.
                    {canPlace &&
                        ' Pilih Tempatkan untuk menempatkan santri atau membuat rencana.'}
                </Empty>
            ) : (
                <ul className="grid gap-2">
                    {holders.map((row) => (
                        <li
                            key={row.id}
                            className="flex flex-wrap items-start justify-between gap-3 rounded-xl border p-3"
                        >
                            <div className="min-w-0 space-y-0.5">
                                <div className="flex flex-wrap items-center gap-2">
                                    <Link
                                        href={`/asrama/penghuni/${row.membership_id}`}
                                        className="font-medium break-words hover:underline"
                                    >
                                        {row.name}
                                    </Link>
                                    <PlacementStatusBadge row={row} />
                                    {row.category === 'PENGAWAS' && (
                                        <Badge variant="outline">
                                            Pengawas
                                        </Badge>
                                    )}
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {row.detail}
                                </p>
                                <p className="text-sm">
                                    {resourceText(row)} ·{' '}
                                    <span className="text-muted-foreground">
                                        {dateLine(row)}
                                    </span>
                                </p>
                                <ResourceWarning row={row} />
                                {openMutations[row.id] && (
                                    <p className="flex items-center gap-1 text-xs text-primary dark:text-accent-foreground">
                                        <ArrowRightLeft className="size-3.5" />
                                        Mutasi diajukan ke{' '}
                                        {openMutations[row.id].to_room},
                                        menunggu persetujuan
                                    </p>
                                )}
                            </div>
                            <div className="flex flex-wrap gap-1">
                                {canMutate &&
                                    row.status === 'AKTIF' &&
                                    row.category === 'PENGHUNI' && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() => onMutate(row)}
                                        >
                                            <ArrowRightLeft />
                                            Mutasi
                                        </Button>
                                    )}
                                {canManage &&
                                    (row.status === 'DIRENCANAKAN' ? (
                                        <>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() => onCheckIn(row)}
                                            >
                                                <LogIn />
                                                Check-in
                                            </Button>
                                            <CancelPlanAction
                                                row={row}
                                                noteMax={noteMax}
                                            />
                                        </>
                                    ) : (
                                        <>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() => onCheckOut(row)}
                                            >
                                                <LogOut />
                                                Check-out
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => onChange(row)}
                                            >
                                                <BedDouble />
                                                Ganti bed/loker
                                            </Button>
                                        </>
                                    ))}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/** Riwayat kamar: penempatan yang selesai atau dibatalkan. */
function HistorySection({ page }: { page: PlacementPage }) {
    if (page.data.length === 0) {
        return <Empty>Belum ada riwayat di kamar ini.</Empty>;
    }

    return (
        <section className="space-y-3">
            <ul className="grid gap-2">
                {page.data.map((row) => (
                    <li
                        key={row.id}
                        className="space-y-0.5 rounded-xl border p-3"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <Link
                                href={`/asrama/penghuni/${row.membership_id}`}
                                className="font-medium break-words hover:underline"
                            >
                                {row.name}
                            </Link>
                            <PlacementStatusBadge row={row} />
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {row.category_label} · {resourceText(row)} ·{' '}
                            {dateLine(row)}
                            {row.end_reason_label &&
                                ` · ${row.end_reason_label}`}
                        </p>
                        {(row.cancel_reason || row.note) && (
                            <p className="text-xs break-words text-muted-foreground">
                                {row.cancel_reason ?? row.note}
                            </p>
                        )}
                    </li>
                ))}
            </ul>
            <Pagination page={page} label="riwayat" />
        </section>
    );
}

/** Aksi lain di pop-up Ubah bed/loker: nonaktifkan/aktifkan dan hapus (masing-masing dengan konfirmasi). */
function ResourceExtraActions({
    kind,
    row,
    acceptsChanges,
    onDone,
}: {
    kind: Kind;
    row: ResourceRow;
    acceptsChanges: boolean;
    onDone: () => void;
}) {
    const label = LABEL[kind].toLowerCase();

    return (
        <div className="flex flex-wrap gap-2 border-t pt-4">
            {(row.is_active || acceptsChanges) && (
                <StatusToggle
                    active={row.is_active}
                    action={`/asrama/${kind}/${row.id}/status`}
                    subject={`${label} ${row.code}`}
                    deactivateNote={`${LABEL[kind]} ini dikeluarkan dari inventaris kamar dan tidak dihitung dalam kapasitas. Riwayatnya tetap tersimpan.`}
                    errorBag={`resourceStatus-${row.id}`}
                    onSuccess={onDone}
                />
            )}
            <ConfirmAction
                trigger={
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        className="text-destructive"
                    >
                        <Trash2 />
                        Hapus
                    </Button>
                }
                title={`Hapus ${label} ${row.code}?`}
                description="Hanya untuk data yang salah ketik. Bed/loker yang pernah dipakai penghuni cukup dinonaktifkan."
                action={`/asrama/${kind}/${row.id}`}
                method="delete"
                confirmLabel="Ya, hapus"
                errorBag={`resourceDelete-${row.id}`}
                onSuccess={onDone}
            />
        </div>
    );
}

function Stat({
    label,
    value,
    alert = false,
}: {
    label: string;
    value: number | string;
    alert?: boolean;
}) {
    return (
        <div
            className={`rounded-xl border p-3 ${alert ? 'border-destructive/40 bg-destructive/5' : ''}`}
        >
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd
                className={`text-xl font-semibold tabular-nums ${alert ? 'text-destructive' : ''}`}
            >
                {value}
            </dd>
        </div>
    );
}

function AddResourceFields({
    kind,
    roomCode,
    batchMax,
    errors,
    next,
}: {
    kind: Kind;
    roomCode: string;
    batchMax: number;
    errors: Record<string, string | undefined>;
    next: number;
}) {
    const [prefix, setPrefix] = useState(
        kind === 'bed' ? `${roomCode}-` : `${roomCode}-L`,
    );
    const [start, setStart] = useState(String(next));
    const [count, setCount] = useState('1');
    const first = Number(start) || 0;
    const total = Math.max(1, Number(count) || 1);
    const width = Math.max(2, String(first + total - 1).length);
    const code = (number: number) =>
        `${prefix.trim().toUpperCase().replace(/\s+/g, '-')}${String(number).padStart(width, '0')}`;

    return (
        <>
            <input type="hidden" name="kind" value={kind} />
            <Field
                id="dm-res-prefix"
                label="Awalan kode"
                hint="Boleh kosong. Spasi diubah menjadi tanda -."
                error={errors.prefix}
            >
                <Input
                    id="dm-res-prefix"
                    name="prefix"
                    value={prefix}
                    onChange={(event) => setPrefix(event.target.value)}
                    maxLength={30}
                    className="uppercase"
                />
            </Field>
            <div className="grid grid-cols-2 gap-4">
                <Field
                    id="dm-res-start"
                    label="Nomor mulai"
                    error={errors.start}
                    required
                >
                    <Input
                        id="dm-res-start"
                        name="start"
                        type="number"
                        min={0}
                        max={999}
                        value={start}
                        onChange={(event) => setStart(event.target.value)}
                        required
                    />
                </Field>
                <Field
                    id="dm-res-count"
                    label="Jumlah"
                    error={errors.count}
                    required
                >
                    <Input
                        id="dm-res-count"
                        name="count"
                        type="number"
                        min={1}
                        max={batchMax}
                        value={count}
                        onChange={(event) => setCount(event.target.value)}
                        required
                        autoFocus
                    />
                </Field>
            </div>
            <p className="rounded-md bg-muted p-3 text-sm">
                Akan dibuat: <span className="font-medium">{code(first)}</span>
                {total > 1 && (
                    <>
                        {' '}
                        s.d.{' '}
                        <span className="font-medium">
                            {code(first + total - 1)}
                        </span>{' '}
                        ({total} {LABEL[kind].toLowerCase()})
                    </>
                )}
            </p>
        </>
    );
}

function ResourceSection({
    kind,
    rows,
    holders,
    counted,
    canManage,
    acceptsChanges,
    onAdd,
    onEdit,
}: {
    kind: Kind;
    rows: ResourceRow[];
    holders: Record<string, Holder>;
    counted: boolean;
    canManage: boolean;
    acceptsChanges: boolean;
    onAdd: () => void;
    onEdit: (row: ResourceRow) => void;
}) {
    const label = LABEL[kind];
    const unavailable = rows.filter(
        (row) => row.is_active && row.status !== 'TERSEDIA',
    ).length;

    return (
        <section className="space-y-3">
            <div className="flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h2 className="font-semibold">
                        {label}{' '}
                        <span className="font-normal text-muted-foreground">
                            ({rows.filter((row) => row.is_active).length}
                            {unavailable > 0 &&
                                `, ${unavailable} tidak tersedia`}
                            )
                        </span>
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        {counted
                            ? 'Dihitung dalam kapasitas kamar.'
                            : 'Hanya dicatat; tidak dihitung dalam kapasitas (lihat dasar kapasitas).'}
                        {canManage &&
                            rows.length > 0 &&
                            ` Ketuk ${label.toLowerCase()} untuk mengubah status.`}
                    </p>
                </div>
                {canManage && acceptsChanges && (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={onAdd}
                    >
                        <Plus />
                        Tambah {label.toLowerCase()}
                    </Button>
                )}
            </div>

            {rows.length === 0 ? (
                <Empty>Belum ada {label.toLowerCase()}.</Empty>
            ) : (
                <ul className="grid grid-cols-[repeat(auto-fill,minmax(7.5rem,1fr))] gap-2">
                    {rows.map((row) => {
                        const holder = holders[row.id];
                        const tone = !row.is_active
                            ? 'border-dashed text-muted-foreground'
                            : row.status === 'TERSEDIA'
                              ? holder
                                  ? 'bg-primary/5'
                                  : 'bg-card'
                              : 'border-destructive/40 bg-destructive/5';
                        const content = (
                            <>
                                <span className="block truncate text-sm font-medium">
                                    {row.code}
                                </span>
                                <span
                                    className={`block truncate text-xs ${row.is_active && row.status !== 'TERSEDIA' ? 'text-destructive' : 'text-muted-foreground'}`}
                                >
                                    {!row.is_active
                                        ? 'Nonaktif'
                                        : row.status === 'TERSEDIA'
                                          ? holder
                                              ? holder.status === 'DIRENCANAKAN'
                                                  ? 'Dipesan'
                                                  : 'Terisi'
                                              : 'Kosong'
                                          : (row.unavailable_reason_label ??
                                            row.status_label)}
                                </span>
                                {holder && (
                                    <span className="block truncate text-xs">
                                        {holder.name}
                                    </span>
                                )}
                            </>
                        );

                        return (
                            <li key={row.id}>
                                {canManage ? (
                                    <button
                                        type="button"
                                        onClick={() => onEdit(row)}
                                        title={
                                            row.unavailable_note ?? undefined
                                        }
                                        className={`min-h-11 w-full rounded-md border px-3 py-2 text-left transition-colors hover:border-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none ${tone}`}
                                    >
                                        {content}
                                    </button>
                                ) : (
                                    <div
                                        title={
                                            row.unavailable_note ?? undefined
                                        }
                                        className={`min-h-11 rounded-md border px-3 py-2 ${tone}`}
                                    >
                                        {content}
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}
