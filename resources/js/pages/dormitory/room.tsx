import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ConfirmAction } from '@/components/confirm-action';
import {
    Empty,
    FacilityDialog,
    Field,
    ResourceFields,
    RoomFields,
    StatusToggle,
} from '@/components/dormitory/facility-forms';
import type {
    Capacity,
    Option,
    ResourceRow,
    RoomOptions,
    RoomRow,
} from '@/components/dormitory/types';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Kind = 'bed' | 'loker';

type Props = {
    room: Omit<RoomRow, 'building_id' | 'capacity'> & {
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
    beds: ResourceRow[];
    lockers: ResourceRow[];
    canManage: boolean;
    options: RoomOptions & {
        reasons: Option[];
        batchMax: number;
        noteMax: number;
    };
};

type OpenDialog =
    | { kind: 'edit-room' }
    | { kind: 'add'; resource: Kind }
    | { kind: 'edit'; resource: Kind; row: ResourceRow };

const LABEL: Record<Kind, string> = { bed: 'Bed', loker: 'Loker' };

/**
 * Detail kamar (PRD-008 §4.3–§4.5, DM-1): ringkasan kapasitas dan inventaris bed & loker. Kapasitas tidak diketik;
 * dihitung server dari bed/loker yang tersedia menurut dasar kapasitas kamar. Penghuni & riwayat menyusul di DM-2.
 */
export default function RoomShow({
    room,
    capacity,
    beds,
    lockers,
    canManage,
    options,
}: Props) {
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
    };
    const title = `Kamar ${room.code}${room.name ? ` · ${room.name}` : ''}`;

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
                                    deactivateNote="Kamar ini tidak menerima penghuni baru. Data dan riwayat tetap tersimpan."
                                    errorBag="roomStatus"
                                />
                            </>
                        )}
                    </div>
                </div>

                <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <Stat label="Kapasitas" value={capacity.effective} />
                    <Stat label="Slot santri" value={capacity.resident_slots} />
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
                        ` ${capacity.supervisor_slots} slot disediakan untuk pengawas yang tinggal di kamar (bagian dari kapasitas).`}
                </p>

                {(['bed', 'loker'] as const).map((kind) => (
                    <ResourceSection
                        key={kind}
                        kind={kind}
                        rows={kind === 'bed' ? beds : lockers}
                        counted={counts(kind)}
                        canManage={canManage}
                        acceptsChanges={room.accepts_changes}
                        onAdd={() => setDialog({ kind: 'add', resource: kind })}
                        onEdit={(row) =>
                            setDialog({ kind: 'edit', resource: kind, row })
                        }
                    />
                ))}
            </div>

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

function Stat({ label, value }: { label: string; value: number | string }) {
    return (
        <div className="rounded-xl border p-3">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-xl font-semibold tabular-nums">{value}</dd>
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
    counted,
    canManage,
    acceptsChanges,
    onAdd,
    onEdit,
}: {
    kind: Kind;
    rows: ResourceRow[];
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
                        const tone = !row.is_active
                            ? 'border-dashed text-muted-foreground'
                            : row.status === 'TERSEDIA'
                              ? 'bg-card'
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
                                          ? 'Tersedia'
                                          : (row.unavailable_reason_label ??
                                            row.status_label)}
                                </span>
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
