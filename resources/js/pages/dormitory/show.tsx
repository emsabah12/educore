import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, ChevronRight, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import {
    CodeNameFields,
    Empty,
    FacilityDialog,
    Field,
    RoomFields,
    Select,
    StatusToggle,
} from '@/components/dormitory/facility-forms';
import { ResidentFilterBar } from '@/components/dormitory/resident-filters';
import { ResidentList } from '@/components/dormitory/residents';
import { SupervisorBadges } from '@/components/dormitory/supervisors';
import type {
    Option,
    PlacementPage,
    ResidentFilters,
    RoomOptions,
    RoomRow,
    SupervisorRow,
    YearEndPolicy,
} from '@/components/dormitory/types';
import { capacityText, occupancyText } from '@/components/dormitory/types';
import Heading from '@/components/heading';
import { PageTabs, usePageTab } from '@/components/page-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type BuildingRow = {
    id: string;
    code: string;
    name: string;
    gender: 'L' | 'P';
    gender_label: string;
    is_active: boolean;
    rooms: RoomRow[];
};

type Props = {
    dormitory: {
        id: string;
        code: string;
        name: string;
        organization: string;
        is_active: boolean;
        default_year_end_policy: YearEndPolicy;
    };
    buildings: BuildingRow[];
    canManage: boolean;
    canViewResidents: boolean;
    residentFilters: ResidentFilters;
    residents: PlacementPage | null;
    supervisors: Record<string, SupervisorRow[]> | null;
    options: RoomOptions & { genders: Option[]; categories: Option[] };
};

type OpenDialog =
    | { kind: 'edit-dormitory' }
    | { kind: 'add-building' }
    | { kind: 'edit-building'; building: BuildingRow }
    | { kind: 'add-room'; building: BuildingRow };

const TABS = ['gedung', 'penghuni', 'wali-kamar', 'pengaturan'] as const;
type Tab = (typeof TABS)[number];

/**
 * Detail asrama (PRD-008 §10, §16): gedung (putra/putri) beserta kamar & keterisiannya, penghuni asrama ini, dan
 * wali kamar per kamar (DM-3), dan pengaturan.
 */
export default function DormitoryShow({
    dormitory,
    buildings,
    canManage,
    canViewResidents,
    residentFilters,
    residents,
    supervisors,
    options,
}: Props) {
    const tabs = canViewResidents
        ? TABS
        : (['gedung', 'wali-kamar', 'pengaturan'] as const);
    const [tab, setTabState] = usePageTab<Tab>(tabs, 'gedung');
    const setTab = (value: Tab) => {
        setTabState(value);

        // Daftar penghuni dimuat saat tab dibuka pertama kali.
        if (value === 'penghuni' && residents === null) {
            router.reload({ only: ['residents'], data: { tab: 'penghuni' } });
        }

        if (value === 'wali-kamar' && supervisors === null) {
            router.reload({
                only: ['supervisors'],
                data: { tab: 'wali-kamar' },
            });
        }
    };
    const over = buildings.reduce(
        (total, building) =>
            total + building.rooms.filter((room) => room.over_capacity).length,
        0,
    );
    const [dialog, setDialog] = useState<OpenDialog | null>(null);
    const close = () => setDialog(null);
    const policyLabel =
        options.policies.find(
            (policy) => policy.value === dormitory.default_year_end_policy,
        )?.label ?? dormitory.default_year_end_policy;
    const base = `/asrama/${dormitory.id}`;

    return (
        <>
            <Head title={dormitory.name} />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
                <div>
                    <Link
                        href="/asrama"
                        className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        Daftar asrama
                    </Link>
                </div>

                <div className="space-y-2">
                    <Heading
                        title={dormitory.name}
                        description={`${dormitory.code} · ${dormitory.organization}`}
                    />
                    <div className="-mt-6 flex flex-wrap items-center gap-2">
                        {!dormitory.is_active && (
                            <Badge variant="outline">Nonaktif</Badge>
                        )}
                        {canManage && (
                            <>
                                {dormitory.is_active && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        onClick={() =>
                                            setDialog({ kind: 'add-building' })
                                        }
                                    >
                                        <Plus />
                                        Tambah gedung
                                    </Button>
                                )}
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        setDialog({ kind: 'edit-dormitory' })
                                    }
                                >
                                    <Pencil />
                                    Ubah asrama
                                </Button>
                                <StatusToggle
                                    active={dormitory.is_active}
                                    action={`${base}/status`}
                                    subject={`asrama ${dormitory.name}`}
                                    deactivateNote="Gedung dan kamarnya tidak menerima penghuni baru. Data dan riwayat tetap tersimpan."
                                    errorBag="dormitoryStatus"
                                />
                            </>
                        )}
                    </div>
                </div>

                <PageTabs
                    label="Bagian asrama"
                    tabs={[
                        { value: 'gedung', label: 'Gedung & kamar' },
                        ...(canViewResidents
                            ? [
                                  {
                                      value: 'penghuni' as const,
                                      label: 'Penghuni',
                                  },
                              ]
                            : []),
                        { value: 'wali-kamar', label: 'Wali kamar' },
                        { value: 'pengaturan', label: 'Pengaturan' },
                    ]}
                    value={tab}
                    onChange={setTab}
                />

                {tab === 'gedung' &&
                    (buildings.length === 0 ? (
                        <Empty>
                            Belum ada gedung.
                            {canManage &&
                                ' Tambah gedung dulu (putra atau putri), lalu kamarnya.'}
                        </Empty>
                    ) : (
                        <div className="space-y-4">
                            {buildings.map((building) => (
                                <BuildingCard
                                    key={building.id}
                                    building={building}
                                    canManage={canManage}
                                    dormitoryActive={dormitory.is_active}
                                    onEdit={() =>
                                        setDialog({
                                            kind: 'edit-building',
                                            building,
                                        })
                                    }
                                    onAddRoom={() =>
                                        setDialog({
                                            kind: 'add-room',
                                            building,
                                        })
                                    }
                                />
                            ))}
                        </div>
                    ))}

                {tab === 'gedung' && over > 0 && (
                    <p className="-order-none rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive">
                        {over} kamar kelebihan muatan (penghuni & rencana
                        melebihi kapasitas). Periksa bed/loker yang rusak di
                        kamar bertanda merah.
                    </p>
                )}

                {tab === 'penghuni' &&
                    canViewResidents &&
                    (residents === null ? (
                        <p className="text-sm text-muted-foreground">
                            Memuat penghuni…
                        </p>
                    ) : (
                        <div className="space-y-4">
                            <ResidentFilterBar
                                url={base}
                                extra={{ tab: 'penghuni' }}
                                filters={residentFilters}
                                buildings={buildings.map((building) => ({
                                    value: building.id,
                                    label: `${building.name} (${building.code})`,
                                }))}
                                categories={options.categories}
                            />
                            <ResidentList
                                page={residents}
                                showDormitory={false}
                                emptyText="Belum ada penghuni yang cocok. Penempatan dilakukan dari halaman kamar."
                            />
                        </div>
                    ))}

                {tab === 'wali-kamar' &&
                    (supervisors === null ? (
                        <p className="text-sm text-muted-foreground">
                            Memuat wali kamar…
                        </p>
                    ) : (
                        <SupervisorOverview
                            buildings={buildings}
                            supervisors={supervisors}
                        />
                    ))}

                {tab === 'pengaturan' && (
                    <section className="space-y-3 rounded-xl border p-4 text-sm">
                        <dl className="grid gap-3 sm:grid-cols-[14rem_1fr]">
                            <dt className="text-muted-foreground">Pemilik</dt>
                            <dd>{dormitory.organization}</dd>
                            <dt className="text-muted-foreground">Kode</dt>
                            <dd>{dormitory.code}</dd>
                            <dt className="text-muted-foreground">
                                Akhir tahun ajaran (bawaan kamar baru)
                            </dt>
                            <dd>
                                {policyLabel}
                                <span className="block text-xs text-muted-foreground">
                                    Bisa diganti per kamar. Proses akhir tahun
                                    dijalankan dari halaman asrama (menyusul).
                                </span>
                            </dd>
                        </dl>
                        {canManage && (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    setDialog({ kind: 'edit-dormitory' })
                                }
                            >
                                <Pencil />
                                Ubah asrama
                            </Button>
                        )}
                    </section>
                )}
            </div>

            <FacilityDialog
                open={dialog?.kind === 'edit-dormitory'}
                onClose={close}
                title="Ubah asrama"
                action={base}
                method="patch"
                errorBag="editDormitory"
                submitLabel="Simpan"
            >
                {(errors) => (
                    <>
                        <CodeNameFields
                            prefix="dm-edit"
                            errors={errors}
                            code={dormitory.code}
                            name={dormitory.name}
                            nameLabel="Nama asrama"
                            codeHint="Unik di yayasan"
                        />
                        <Field
                            id="dm-edit-policy"
                            label="Akhir tahun ajaran (bawaan kamar baru)"
                            error={errors.default_year_end_policy}
                        >
                            <Select
                                id="dm-edit-policy"
                                name="default_year_end_policy"
                                options={options.policies}
                                defaultValue={dormitory.default_year_end_policy}
                            />
                        </Field>
                    </>
                )}
            </FacilityDialog>

            {(dialog?.kind === 'add-building' ||
                dialog?.kind === 'edit-building') && (
                <FacilityDialog
                    open
                    onClose={close}
                    title={
                        dialog.kind === 'add-building'
                            ? 'Tambah gedung'
                            : `Ubah gedung ${dialog.building.name}`
                    }
                    description="Semua kamar di gedung ini mengikuti jenis kelaminnya."
                    action={
                        dialog.kind === 'add-building'
                            ? `${base}/gedung`
                            : `/asrama/gedung/${dialog.building.id}`
                    }
                    method={dialog.kind === 'add-building' ? 'post' : 'patch'}
                    errorBag="buildingForm"
                    submitLabel="Simpan gedung"
                >
                    {(errors) => {
                        const building =
                            dialog.kind === 'edit-building'
                                ? dialog.building
                                : undefined;

                        return (
                            <>
                                <CodeNameFields
                                    prefix="dm-building"
                                    errors={errors}
                                    code={building?.code}
                                    name={building?.name}
                                    nameLabel="Nama gedung"
                                    codeHint="Mis. A"
                                />
                                <Field
                                    id="dm-building-gender"
                                    label="Untuk"
                                    error={errors.gender}
                                    required
                                >
                                    <Select
                                        id="dm-building-gender"
                                        name="gender"
                                        options={options.genders}
                                        defaultValue={building?.gender ?? ''}
                                        placeholder="Pilih putra atau putri"
                                    />
                                </Field>
                            </>
                        );
                    }}
                </FacilityDialog>
            )}

            {dialog?.kind === 'add-room' && (
                <FacilityDialog
                    open
                    onClose={close}
                    title={`Tambah kamar di ${dialog.building.name}`}
                    description={`Kamar ${dialog.building.gender_label.toLowerCase()}. Kapasitas dihitung dari bed/loker yang ditambahkan di halaman kamar.`}
                    action={`/asrama/gedung/${dialog.building.id}/kamar`}
                    errorBag="roomForm"
                    submitLabel="Simpan kamar"
                >
                    {(errors) => (
                        <RoomFields
                            prefix="dm-room"
                            errors={errors}
                            options={options}
                        />
                    )}
                </FacilityDialog>
            )}
        </>
    );
}

function BuildingCard({
    building,
    canManage,
    dormitoryActive,
    onEdit,
    onAddRoom,
}: {
    building: BuildingRow;
    canManage: boolean;
    dormitoryActive: boolean;
    onEdit: () => void;
    onAddRoom: () => void;
}) {
    const capacity = building.rooms
        .filter((room) => room.is_active)
        .reduce((total, room) => total + room.capacity.effective, 0);

    return (
        <section className="rounded-xl border">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b p-4">
                <div className="min-w-0">
                    <p className="flex flex-wrap items-center gap-2 font-medium">
                        <span className="break-words">
                            {building.name}{' '}
                            <span className="font-normal text-muted-foreground">
                                ({building.code})
                            </span>
                        </span>
                        <Badge variant="secondary">
                            {building.gender_label}
                        </Badge>
                        {!building.is_active && (
                            <Badge variant="outline">Nonaktif</Badge>
                        )}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {building.rooms.length} kamar · kapasitas {capacity}{' '}
                        tempat
                    </p>
                </div>
                {canManage && (
                    <div className="flex flex-wrap gap-2">
                        {building.is_active && dormitoryActive && (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={onAddRoom}
                            >
                                <Plus />
                                Tambah kamar
                            </Button>
                        )}
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={onEdit}
                        >
                            <Pencil />
                            Ubah
                        </Button>
                        <StatusToggle
                            active={building.is_active}
                            action={`/asrama/gedung/${building.id}/status`}
                            subject={`gedung ${building.name}`}
                            deactivateNote="Kamar di gedung ini tidak menerima penghuni baru. Data dan riwayat tetap tersimpan."
                            errorBag={`buildingStatus-${building.id}`}
                        />
                    </div>
                )}
            </div>

            {building.rooms.length === 0 ? (
                <p className="p-4 text-sm text-muted-foreground">
                    Belum ada kamar.
                </p>
            ) : (
                <ul className="divide-y">
                    {building.rooms.map((room) => (
                        <li key={room.id}>
                            <Link
                                href={`/asrama/kamar/${room.id}`}
                                className="flex items-center justify-between gap-3 px-4 py-3 hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:outline-none"
                            >
                                <div className="min-w-0 space-y-0.5">
                                    <p className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                        <span className="break-words">
                                            {room.code}
                                            {room.name && (
                                                <span className="font-normal text-muted-foreground">
                                                    {' '}
                                                    · {room.name}
                                                </span>
                                            )}
                                        </span>
                                        {!room.is_active && (
                                            <Badge variant="outline">
                                                Nonaktif
                                            </Badge>
                                        )}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {room.composition_label} · dasar{' '}
                                        {room.capacity_basis_label.toLowerCase()}{' '}
                                        ·{' '}
                                        {capacityText(
                                            room.capacity,
                                            room.composition,
                                        )}
                                    </p>
                                    <p
                                        className={`text-xs ${room.over_capacity ? 'font-medium text-destructive' : 'text-muted-foreground'}`}
                                    >
                                        {occupancyText(
                                            room.occupancy,
                                            room.capacity,
                                        )}
                                        {room.over_capacity &&
                                            ' · kelebihan muatan'}
                                    </p>
                                </div>
                                <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/**
 * Tab Wali kamar (PRD-008 §17.3): per gedung, setiap kamar aktif dengan wali kamarnya. Kamar tanpa wali kamar
 * ditandai. Tambah/akhiri dilakukan di halaman kamar.
 */
function SupervisorOverview({
    buildings,
    supervisors,
}: {
    buildings: BuildingRow[];
    supervisors: Record<string, SupervisorRow[]>;
}) {
    const active = buildings.filter((building) => building.is_active);
    const without = active.reduce(
        (total, building) =>
            total +
            building.rooms.filter(
                (room) =>
                    room.is_active && (supervisors[room.id] ?? []).length === 0,
            ).length,
        0,
    );

    if (active.every((building) => building.rooms.length === 0)) {
        return <Empty>Belum ada kamar. Tambah gedung dan kamar dulu.</Empty>;
    }

    return (
        <div className="space-y-4">
            <p className="text-sm text-muted-foreground">
                {without > 0
                    ? `${without} kamar aktif belum punya wali kamar.`
                    : 'Semua kamar aktif sudah punya wali kamar.'}{' '}
                Tambah atau akhiri wali kamar dari halaman kamar.
            </p>
            {active.map((building) => (
                <section key={building.id} className="rounded-xl border">
                    <h2 className="border-b px-4 py-2 text-sm font-semibold">
                        {building.name}{' '}
                        <span className="font-normal text-muted-foreground">
                            ({building.gender_label})
                        </span>
                    </h2>
                    <ul className="divide-y">
                        {building.rooms
                            .filter((room) => room.is_active)
                            .map((room) => {
                                const rows = supervisors[room.id] ?? [];

                                return (
                                    <li
                                        key={room.id}
                                        className="flex flex-wrap items-start gap-x-4 gap-y-1 px-4 py-2.5"
                                    >
                                        <Link
                                            href={`/asrama/kamar/${room.id}`}
                                            className="w-24 shrink-0 font-medium hover:underline"
                                        >
                                            {room.code}
                                        </Link>
                                        {rows.length === 0 ? (
                                            <span className="text-sm text-destructive">
                                                Belum ada wali kamar
                                            </span>
                                        ) : (
                                            <ul className="flex min-w-0 flex-1 flex-col gap-1">
                                                {rows.map((row) => (
                                                    <li
                                                        key={row.id}
                                                        className="flex flex-wrap items-center gap-2 text-sm"
                                                    >
                                                        {row.name}
                                                        <SupervisorBadges
                                                            row={row}
                                                        />
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </li>
                                );
                            })}
                    </ul>
                </section>
            ))}
        </div>
    );
}
