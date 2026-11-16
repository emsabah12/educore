import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, ChevronRight, Download } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { Empty } from '@/components/dormitory/facility-forms';
import type { Option } from '@/components/dormitory/types';
import { dateText } from '@/components/dormitory/types';
import Heading from '@/components/heading';
import { selectClass } from '@/components/native-select';
import { PageTabs, usePageTab } from '@/components/page-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

type Metrics = {
    rooms: number;
    capacity: number;
    active: number;
    residents: number;
    supervisors: number;
    planned: number;
    available: number;
    percent: number | null;
    beds_total: number;
    beds_available: number;
    lockers_total: number;
    lockers_available: number;
    unavailable: number;
    over_capacity: number;
};

type RoomReport = {
    id: string;
    code: string;
    name: string | null;
    counted: boolean;
    capacity_basis_label: string;
    metrics: Metrics;
};

type BuildingReport = {
    id: string;
    code: string;
    name: string;
    gender_label: string;
    is_active: boolean;
    metrics: Metrics;
    rooms: RoomReport[];
};

type DormitoryReport = {
    id: string;
    code: string;
    name: string;
    organization: string;
    is_active: boolean;
    metrics: Metrics;
    buildings: BuildingReport[];
};

type Movement = {
    start: number;
    entries: number;
    left_dormitory: number;
    left_school: number;
    year_end: number;
    other: number;
    mutation_in: number;
    mutation_out: number;
    emergency: number;
    end: number;
};

type EventRow = {
    date: string;
    name: string;
    category_label: string;
    dormitory: string;
    building: string;
    room: string;
    kind: string;
    kind_label: string;
    detail: string | null;
};

type Filters = {
    dormitory: string | null;
    gender: 'putra' | 'putri' | null;
    from: string;
    to: string;
};

type Props = {
    filters: Filters;
    rangeAdjusted: boolean;
    today: string;
    options: { dormitories: Option[] };
    current: { dormitories: DormitoryReport[]; totals: Metrics };
    movements: {
        dormitories: {
            id: string;
            code: string;
            name: string;
            movement: Movement;
        }[];
        totals: Movement;
    } | null;
    events: {
        rows: EventRow[];
        total: number;
        page: number;
        last_page: number;
    } | null;
};

const TABS = ['sekarang', 'riwayat'] as const;
type Tab = (typeof TABS)[number];

const number = new Intl.NumberFormat('id-ID');
const percentFormat = new Intl.NumberFormat('id-ID', {
    maximumFractionDigits: 1,
});

const percentText = (value: number | null) =>
    value === null ? '–' : `${percentFormat.format(value)}%`;

/**
 * Laporan hunian asrama (PRD-008 §12 DM-6, §21): hunian sekarang per asrama → gedung → kamar, dan pergerakan
 * (masuk, keluar, mutasi) dalam rentang tanggal. % hunian = Aktif ÷ kapasitas efektif; rencana tidak ikut persen.
 */
export default function OccupancyReport({
    filters,
    rangeAdjusted,
    today,
    options,
    current,
    movements,
    events,
}: Props) {
    const [tab, setTabState] = usePageTab<Tab>(TABS, 'sekarang');
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);

    const query = (overrides: Record<string, string | null> = {}) => {
        const merged: Record<string, string | null> = {
            asrama: filters.dormitory,
            jk: filters.gender,
            dari: filters.from,
            sampai: filters.to,
            ...overrides,
        };
        const clean: Record<string, string> = {};

        for (const [key, value] of Object.entries(merged)) {
            if (value) {
                clean[key] = value;
            }
        }

        return clean;
    };

    const visit = (overrides: Record<string, string | null>) =>
        router.get(
            '/asrama/laporan',
            {
                ...query(overrides),
                ...(tab === 'riwayat' ? { tab: 'riwayat' } : {}),
            },
            { preserveScroll: true, preserveState: true },
        );

    const setTab = (value: Tab) => {
        setTabState(value);

        // Riwayat dimuat saat tab dibuka pertama kali.
        if (value === 'riwayat' && movements === null) {
            router.reload({
                only: ['movements', 'events'],
                data: { tab: 'riwayat' },
            });
        }
    };

    const exportUrl = `/asrama/laporan/unduh?${new URLSearchParams(query())}`;
    const { totals } = current;
    const single = current.dormitories.length === 1;

    return (
        <>
            <Head title="Laporan hunian" />

            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-4 p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <Heading
                        title="Laporan hunian"
                        description="Kapasitas dan keterisian kamar asrama di cakupan Anda, serta pergerakan penghuni dalam rentang tanggal."
                    />
                    {options.dormitories.length > 0 && (
                        <Button variant="outline" asChild>
                            <a href={exportUrl}>
                                <Download />
                                Unduh .xlsx
                            </a>
                        </Button>
                    )}
                </div>

                {options.dormitories.length === 0 ? (
                    <Empty>Belum ada asrama di cakupan Anda.</Empty>
                ) : (
                    <>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            {options.dormitories.length > 1 && (
                                <div className="grid gap-1.5">
                                    <Label htmlFor="rp-dorm">Asrama</Label>
                                    <select
                                        id="rp-dorm"
                                        className={selectClass}
                                        value={filters.dormitory ?? ''}
                                        onChange={(event) =>
                                            visit({
                                                asrama:
                                                    event.target.value || null,
                                                halaman: null,
                                            })
                                        }
                                    >
                                        <option value="">Semua asrama</option>
                                        {options.dormitories.map((option) => (
                                            <option
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            )}
                            <div className="grid gap-1.5">
                                <Label htmlFor="rp-gender">Gedung</Label>
                                <select
                                    id="rp-gender"
                                    className={selectClass}
                                    value={filters.gender ?? ''}
                                    onChange={(event) =>
                                        visit({
                                            jk: event.target.value || null,
                                            halaman: null,
                                        })
                                    }
                                >
                                    <option value="">Putra & putri</option>
                                    <option value="putra">Putra</option>
                                    <option value="putri">Putri</option>
                                </select>
                            </div>
                        </div>

                        <PageTabs
                            label="Bagian laporan"
                            tabs={[
                                { value: 'sekarang', label: 'Hunian sekarang' },
                                { value: 'riwayat', label: 'Riwayat' },
                            ]}
                            value={tab}
                            onChange={setTab}
                        />

                        {tab === 'sekarang' ? (
                            <section className="flex flex-col gap-4">
                                <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                                    <Stat
                                        label="Kapasitas efektif"
                                        value={number.format(totals.capacity)}
                                        hint={`${number.format(totals.rooms)} kamar`}
                                    />
                                    <Stat
                                        label="Terisi"
                                        value={number.format(totals.active)}
                                        hint={`${number.format(totals.residents)} santri · ${number.format(totals.supervisors)} pengawas`}
                                    />
                                    <Stat
                                        label="Direncanakan"
                                        value={number.format(totals.planned)}
                                    />
                                    <Stat
                                        label="Tersedia"
                                        value={number.format(totals.available)}
                                    />
                                    <Stat
                                        label="Hunian"
                                        value={percentText(totals.percent)}
                                        hint="terisi ÷ kapasitas"
                                    />
                                    <Stat
                                        label="Perlu perhatian"
                                        value={number.format(
                                            totals.over_capacity,
                                        )}
                                        hint={`kamar kelebihan muatan · ${number.format(totals.unavailable)} bed/loker tidak tersedia`}
                                        warn={
                                            totals.over_capacity > 0 ||
                                            totals.unavailable > 0
                                        }
                                    />
                                </dl>

                                {current.dormitories.length === 0 ? (
                                    <Empty>
                                        Tidak ada kamar yang cocok dengan
                                        saringan.
                                    </Empty>
                                ) : (
                                    current.dormitories.map((dormitory) => (
                                        <DormitoryCard
                                            key={dormitory.id}
                                            dormitory={dormitory}
                                            open={single}
                                        />
                                    ))
                                )}
                            </section>
                        ) : (
                            <section className="flex flex-col gap-4">
                                <form
                                    className="flex flex-wrap items-end gap-3"
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        visit({
                                            dari: from,
                                            sampai: to,
                                            halaman: null,
                                        });
                                    }}
                                >
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="rp-from">Dari</Label>
                                        <Input
                                            id="rp-from"
                                            type="date"
                                            value={from}
                                            max={today}
                                            required
                                            onChange={(event) =>
                                                setFrom(event.target.value)
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="rp-to">Sampai</Label>
                                        <Input
                                            id="rp-to"
                                            type="date"
                                            value={to}
                                            max={today}
                                            required
                                            onChange={(event) =>
                                                setTo(event.target.value)
                                            }
                                        />
                                    </div>
                                    <Button type="submit" variant="outline">
                                        Tampilkan
                                    </Button>
                                </form>
                                <p className="-mt-2 text-xs text-muted-foreground">
                                    Rentang maks. 1 tahun. Hunian awal = yang
                                    tinggal sebelum tanggal awal; hunian akhir =
                                    yang masih tinggal di akhir tanggal sampai.
                                    {rangeAdjusted &&
                                        ' Rentang disesuaikan karena melebihi batas atau melewati hari ini.'}
                                </p>

                                {movements === null || events === null ? (
                                    <p className="text-sm text-muted-foreground">
                                        Memuat riwayat…
                                    </p>
                                ) : (
                                    <>
                                        <MovementTable
                                            rows={movements.dormitories}
                                            totals={movements.totals}
                                        />
                                        <EventList
                                            events={events}
                                            range={`${dateText(filters.from)} s.d. ${dateText(filters.to)}`}
                                            onPage={(page) =>
                                                visit({
                                                    halaman: String(page),
                                                })
                                            }
                                        />
                                    </>
                                )}
                            </section>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

function DormitoryCard({
    dormitory,
    open,
}: {
    dormitory: DormitoryReport;
    open: boolean;
}) {
    const { metrics } = dormitory;

    return (
        <details
            open={open}
            className="group rounded-xl border bg-card shadow-card"
        >
            <summary className="flex cursor-pointer list-none flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between [&::-webkit-details-marker]:hidden">
                <div className="flex items-start gap-2">
                    <ChevronRight className="mt-0.5 size-4 shrink-0 transition-transform group-open:rotate-90" />
                    <div>
                        <p className="font-medium">
                            {dormitory.name}
                            {!dormitory.is_active && (
                                <Badge variant="outline" className="ml-2">
                                    Nonaktif
                                </Badge>
                            )}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {dormitory.code} · {dormitory.organization}
                        </p>
                    </div>
                </div>
                <MetricLine metrics={metrics} />
            </summary>

            <div className="flex flex-col gap-3 border-t p-4">
                {dormitory.buildings.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Belum ada gedung.
                    </p>
                ) : (
                    dormitory.buildings.map((building) => (
                        <div key={building.id} className="flex flex-col gap-2">
                            <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                <p className="text-sm font-medium">
                                    {building.name}{' '}
                                    <span className="text-muted-foreground">
                                        ({building.code})
                                    </span>
                                    <Badge variant="secondary" className="ml-2">
                                        {building.gender_label}
                                    </Badge>
                                    {!building.is_active && (
                                        <Badge
                                            variant="outline"
                                            className="ml-1"
                                        >
                                            Nonaktif
                                        </Badge>
                                    )}
                                </p>
                                <MetricLine metrics={building.metrics} />
                            </div>
                            {building.rooms.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada kamar.
                                </p>
                            ) : (
                                <RoomTable rooms={building.rooms} />
                            )}
                        </div>
                    ))
                )}
            </div>
        </details>
    );
}

function MetricLine({ metrics }: { metrics: Metrics }) {
    return (
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm tabular-nums sm:justify-end">
            <span>
                Terisi <strong>{number.format(metrics.active)}</strong>/
                {number.format(metrics.capacity)}
            </span>
            {metrics.planned > 0 && (
                <span className="text-muted-foreground">
                    {number.format(metrics.planned)} rencana
                </span>
            )}
            <span className="text-muted-foreground">
                tersedia {number.format(metrics.available)}
            </span>
            <PercentBar percent={metrics.percent} />
            {metrics.over_capacity > 0 && (
                <Badge variant="destructive">
                    {number.format(metrics.over_capacity)} kelebihan muatan
                </Badge>
            )}
        </div>
    );
}

function PercentBar({ percent }: { percent: number | null }) {
    return (
        <span className="flex items-center gap-1.5">
            <span
                className="h-1.5 w-16 overflow-hidden rounded-full bg-muted"
                aria-hidden
            >
                <span
                    className={cn(
                        'block h-full rounded-full bg-primary',
                        percent !== null && percent > 100 && 'bg-destructive',
                    )}
                    style={{ width: `${Math.min(100, percent ?? 0)}%` }}
                />
            </span>
            <span className="w-12 text-right font-medium">
                {percentText(percent)}
            </span>
        </span>
    );
}

function RoomTable({ rooms }: { rooms: RoomReport[] }) {
    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full min-w-[42rem] text-sm">
                <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                    <tr>
                        <th className="px-3 py-2 font-medium">Kamar</th>
                        <th className="px-3 py-2 text-right font-medium">
                            Kapasitas
                        </th>
                        <th className="px-3 py-2 text-right font-medium">
                            Santri
                        </th>
                        <th className="px-3 py-2 text-right font-medium">
                            Pengawas
                        </th>
                        <th className="px-3 py-2 text-right font-medium">
                            Rencana
                        </th>
                        <th className="px-3 py-2 text-right font-medium">
                            Tersedia
                        </th>
                        <th className="px-3 py-2 text-right font-medium">
                            Hunian
                        </th>
                        <th className="px-3 py-2 font-medium">Bed · loker</th>
                    </tr>
                </thead>
                <tbody>
                    {rooms.map((room) => {
                        const metrics = room.metrics;

                        return (
                            <tr
                                key={room.id}
                                className={cn(
                                    'border-t tabular-nums',
                                    !room.counted && 'text-muted-foreground',
                                )}
                            >
                                <td className="px-3 py-2">
                                    <Link
                                        href={`/asrama/kamar/${room.id}`}
                                        className="font-medium text-foreground hover:underline"
                                    >
                                        {room.code}
                                    </Link>
                                    <span className="block text-xs text-muted-foreground">
                                        {room.capacity_basis_label}
                                    </span>
                                    <span className="flex flex-wrap gap-1">
                                        {!room.counted && (
                                            <Badge variant="outline">
                                                Nonaktif
                                            </Badge>
                                        )}
                                        {metrics.over_capacity > 0 && (
                                            <Badge variant="destructive">
                                                Kelebihan muatan
                                            </Badge>
                                        )}
                                    </span>
                                </td>
                                <td className="px-3 py-2 text-right">
                                    {metrics.capacity}
                                </td>
                                <td className="px-3 py-2 text-right">
                                    {metrics.residents}
                                </td>
                                <td className="px-3 py-2 text-right">
                                    {metrics.supervisors}
                                </td>
                                <td className="px-3 py-2 text-right">
                                    {metrics.planned}
                                </td>
                                <td className="px-3 py-2 text-right">
                                    {metrics.available}
                                </td>
                                <td className="px-3 py-2 text-right font-medium">
                                    {percentText(metrics.percent)}
                                </td>
                                <td className="px-3 py-2 text-xs">
                                    Bed {metrics.beds_available}/
                                    {metrics.beds_total} · Loker{' '}
                                    {metrics.lockers_available}/
                                    {metrics.lockers_total}
                                    {metrics.unavailable > 0 && (
                                        <span className="flex items-center gap-1 text-amber-700 dark:text-amber-400">
                                            <AlertTriangle className="size-3" />
                                            {metrics.unavailable} tidak tersedia
                                        </span>
                                    )}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

const MOVEMENT_COLUMNS: { key: keyof Movement; label: string }[] = [
    { key: 'start', label: 'Hunian awal' },
    { key: 'entries', label: 'Masuk' },
    { key: 'left_dormitory', label: 'Keluar asrama' },
    { key: 'left_school', label: 'Lulus/keluar lembaga' },
    { key: 'year_end', label: 'Akhir tahun' },
    { key: 'other', label: 'Keluar lainnya' },
    { key: 'mutation_in', label: 'Mutasi masuk' },
    { key: 'mutation_out', label: 'Mutasi keluar' },
    { key: 'emergency', label: 'Mutasi darurat' },
    { key: 'end', label: 'Hunian akhir' },
];

function MovementTable({
    rows,
    totals,
}: {
    rows: { id: string; name: string; movement: Movement }[];
    totals: Movement;
}) {
    if (rows.length === 0) {
        return <Empty>Tidak ada asrama yang cocok dengan saringan.</Empty>;
    }

    return (
        <div className="overflow-x-auto rounded-xl border bg-card shadow-card">
            <table className="w-full min-w-[56rem] text-sm tabular-nums">
                <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                    <tr>
                        <th className="px-3 py-2 font-medium">Asrama</th>
                        {MOVEMENT_COLUMNS.map((column) => (
                            <th
                                key={column.key}
                                className="px-3 py-2 text-right font-medium"
                            >
                                {column.label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.id} className="border-t">
                            <td className="px-3 py-2 font-medium">
                                {row.name}
                            </td>
                            {MOVEMENT_COLUMNS.map((column) => (
                                <td
                                    key={column.key}
                                    className="px-3 py-2 text-right"
                                >
                                    {number.format(row.movement[column.key])}
                                </td>
                            ))}
                        </tr>
                    ))}
                    {rows.length > 1 && (
                        <tr className="border-t bg-muted/30 font-semibold">
                            <td className="px-3 py-2">Total</td>
                            {MOVEMENT_COLUMNS.map((column) => (
                                <td
                                    key={column.key}
                                    className="px-3 py-2 text-right"
                                >
                                    {number.format(totals[column.key])}
                                </td>
                            ))}
                        </tr>
                    )}
                </tbody>
            </table>
        </div>
    );
}

function EventList({
    events,
    range,
    onPage,
}: {
    events: NonNullable<Props['events']>;
    range: string;
    onPage: (page: number) => void;
}) {
    return (
        <div className="flex flex-col gap-2">
            <h3 className="text-sm font-medium">
                Kejadian {range}{' '}
                <span className="text-muted-foreground">
                    ({number.format(events.total)})
                </span>
            </h3>

            {events.total === 0 ? (
                <Empty>Tidak ada pergerakan penghuni dalam rentang ini.</Empty>
            ) : (
                <ul className="divide-y rounded-xl border bg-card shadow-card">
                    {events.rows.map((event, index) => (
                        <li
                            key={`${event.date}-${event.name}-${index}`}
                            className="flex flex-col gap-1 p-3 text-sm sm:flex-row sm:items-center sm:gap-4"
                        >
                            <span className="w-24 shrink-0 text-muted-foreground tabular-nums">
                                {dateText(event.date)}
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="font-medium">
                                    {event.name}
                                </span>{' '}
                                <span className="text-xs text-muted-foreground">
                                    {event.category_label} · {event.dormitory} ·{' '}
                                    {event.building} · {event.room}
                                </span>
                            </span>
                            <span className="flex flex-wrap items-center gap-2">
                                <Badge variant={kindVariant(event.kind)}>
                                    {event.kind_label}
                                </Badge>
                                {event.detail && (
                                    <span className="text-xs text-muted-foreground">
                                        {event.detail}
                                    </span>
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            {events.last_page > 1 && (
                <nav
                    aria-label="Halaman"
                    className="flex flex-wrap items-center justify-between gap-2 text-sm"
                >
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={events.page <= 1}
                        onClick={() => onPage(events.page - 1)}
                    >
                        Sebelumnya
                    </Button>
                    <span className="text-muted-foreground">
                        Halaman {events.page} dari {events.last_page}
                    </span>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={events.page >= events.last_page}
                        onClick={() => onPage(events.page + 1)}
                    >
                        Berikutnya
                    </Button>
                </nav>
            )}
        </div>
    );
}

function kindVariant(kind: string): 'default' | 'secondary' | 'outline' {
    if (kind === 'MASUK') {
        return 'default';
    }

    return kind.startsWith('MUTASI') ? 'secondary' : 'outline';
}

function Stat({
    label,
    value,
    hint,
    warn = false,
}: {
    label: string;
    value: string;
    hint?: ReactNode;
    warn?: boolean;
}) {
    return (
        <div
            className={cn(
                'rounded-xl border bg-card p-3 shadow-card',
                warn && 'border-amber-300 dark:border-amber-700',
            )}
        >
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-lg font-semibold tabular-nums">{value}</dd>
            {hint && <dd className="text-xs text-muted-foreground">{hint}</dd>}
        </div>
    );
}
