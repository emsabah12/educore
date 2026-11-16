import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, CircleAlert, CircleDashed } from 'lucide-react';
import type { AttendanceKindRow } from '@/components/dormitory/attendance-kinds';
import type { AttendanceRecapProps } from '@/components/dormitory/attendance-recap';
import { AttendanceRecapView } from '@/components/dormitory/attendance-recap';
import { Empty } from '@/components/dormitory/facility-forms';
import type { Option } from '@/components/dormitory/types';
import Heading from '@/components/heading';
import { selectClass } from '@/components/native-select';
import { PageTabs } from '@/components/page-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type RoomState = {
    residents: number;
    filled: boolean;
    counts: { H: number; I: number; S: number; T: number };
    recorded_by: string | null;
    updated_at: string | null;
};

type Props = {
    tab: 'isi' | 'rekap';
    options: { dormitories: Option[] };
    dormitory: { id: string; code: string; name: string } | null;
    kinds: AttendanceKindRow[];
    canFill: boolean;
    canManageKinds: boolean;
    fill: {
        date: string;
        window: { min: string; max: string };
        kind: string | null;
        rooms: {
            id: string;
            code: string;
            building: string;
            is_active: boolean;
            state: RoomState | null;
        }[];
    } | null;
    recap: AttendanceRecapProps | null;
};

const stampFormat = new Intl.DateTimeFormat('id-ID', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

/**
 * Absen asrama (PRD-008 §22): tab Isi absen (semua kamar satu asrama untuk satu tanggal + jenis absen) dan
 * Rekap bulanan. Mengisi per kamar lewat lembar absen kamar.
 */
export default function DormitoryAttendance({
    tab,
    options,
    dormitory,
    kinds,
    canFill,
    canManageKinds,
    fill,
    recap,
}: Props) {
    const go = (params: Record<string, string | null>) =>
        router.get(
            '/asrama/absen',
            Object.fromEntries(
                Object.entries({
                    asrama: dormitory?.id ?? null,
                    ...params,
                }).filter((entry): entry is [string, string] =>
                    Boolean(entry[1]),
                ),
            ),
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Absen asrama" />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Absen asrama"
                    description="Absen penghuni per kamar (santri & pengawas) dan rekap bulanannya."
                />

                {dormitory === null ? (
                    <Empty>Belum ada asrama di cakupan Anda.</Empty>
                ) : (
                    <>
                        {options.dormitories.length > 1 && (
                            <div className="grid max-w-sm gap-1.5">
                                <Label htmlFor="ab-dorm">Asrama</Label>
                                <select
                                    id="ab-dorm"
                                    className={selectClass}
                                    value={dormitory.id}
                                    onChange={(event) =>
                                        go({
                                            asrama: event.target.value,
                                            tab:
                                                tab === 'rekap'
                                                    ? 'rekap'
                                                    : null,
                                        })
                                    }
                                >
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

                        <PageTabs
                            label="Bagian absen"
                            tabs={[
                                { value: 'isi', label: 'Isi absen' },
                                { value: 'rekap', label: 'Rekap bulanan' },
                            ]}
                            value={tab}
                            onChange={(value) =>
                                go({ tab: value === 'rekap' ? 'rekap' : null })
                            }
                        />

                        {kinds.length === 0 && (
                            <p className="flex gap-2 rounded-md border border-amber-500/50 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                                <CircleAlert className="mt-0.5 size-4 shrink-0" />
                                <span>
                                    Asrama ini belum punya jenis absen aktif
                                    (mis. Subuh, Malam).{' '}
                                    {canManageKinds ? (
                                        <Link
                                            href={`/asrama/${dormitory.id}?tab=pengaturan`}
                                            className="font-medium underline underline-offset-2"
                                        >
                                            Tambahkan di tab Pengaturan
                                        </Link>
                                    ) : (
                                        'Minta pengurus asrama menambahkannya.'
                                    )}
                                </span>
                            </p>
                        )}

                        {tab === 'isi' && fill && fill.kind && (
                            <FillOverview
                                fill={{ ...fill, kind: fill.kind }}
                                kinds={kinds}
                                canFill={canFill}
                                onChange={(params) => go(params)}
                            />
                        )}

                        {tab === 'rekap' && recap && (
                            <AttendanceRecapView
                                recap={recap}
                                kinds={kinds}
                                url="/asrama/absen"
                                exportUrl="/asrama/absen/unduh"
                                extraQuery={{
                                    asrama: dormitory.id,
                                    tab: 'rekap',
                                }}
                            />
                        )}
                    </>
                )}
            </div>
        </>
    );
}

function FillOverview({
    fill,
    kinds,
    canFill,
    onChange,
}: {
    fill: NonNullable<Props['fill']> & { kind: string };
    kinds: AttendanceKindRow[];
    canFill: boolean;
    onChange: (params: Record<string, string | null>) => void;
}) {
    const filled = fill.rooms.filter((room) => room.state?.filled).length;
    const withResidents = fill.rooms.filter(
        (room) => (room.state?.residents ?? 0) > 0,
    ).length;

    return (
        <section className="flex flex-col gap-4">
            <div className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] items-end gap-3 rounded-xl border p-4">
                <div className="grid gap-1.5">
                    <Label htmlFor="ab-kind">Jenis absen</Label>
                    <select
                        id="ab-kind"
                        className={selectClass}
                        value={fill.kind}
                        onChange={(event) =>
                            onChange({
                                jenis: event.target.value,
                                tanggal: fill.date,
                            })
                        }
                    >
                        {kinds.map((row) => (
                            <option key={row.id} value={row.id}>
                                {row.name}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="ab-date">Tanggal</Label>
                    <Input
                        id="ab-date"
                        type="date"
                        value={fill.date}
                        min={fill.window.min}
                        max={fill.window.max}
                        onChange={(event) => {
                            if (event.target.value !== '') {
                                onChange({
                                    jenis: fill.kind,
                                    tanggal: event.target.value,
                                });
                            }
                        }}
                    />
                </div>
                <p className="text-sm text-muted-foreground">
                    {filled} dari {withResidents} kamar berpenghuni sudah diisi.
                </p>
            </div>

            {fill.rooms.length === 0 ? (
                <Empty>Asrama ini belum punya kamar.</Empty>
            ) : (
                <ul className="divide-y rounded-xl border bg-card shadow-card">
                    {fill.rooms.map((room) => {
                        const state = room.state;
                        const empty = (state?.residents ?? 0) === 0;
                        const href = `/asrama/kamar/${room.id}/absen?jenis=${fill.kind}&tanggal=${fill.date}`;

                        return (
                            <li
                                key={room.id}
                                className="flex flex-col gap-2 p-3 text-sm sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div className="flex items-start gap-2">
                                    {state?.filled ? (
                                        <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-emerald-600" />
                                    ) : (
                                        <CircleDashed className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                    )}
                                    <div>
                                        <p className="font-medium">
                                            {room.code}{' '}
                                            <span className="font-normal text-muted-foreground">
                                                {room.building}
                                            </span>
                                            {!room.is_active && (
                                                <Badge
                                                    variant="outline"
                                                    className="ml-2"
                                                >
                                                    Nonaktif
                                                </Badge>
                                            )}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {state?.residents ?? 0} penghuni ·{' '}
                                            {state?.filled
                                                ? `Diisi${state.recorded_by ? ` oleh ${state.recorded_by}` : ''}${state.updated_at ? `, ${stampFormat.format(new Date(state.updated_at))}` : ''}`
                                                : empty
                                                  ? 'Tidak ada penghuni'
                                                  : 'Belum diisi'}
                                        </p>
                                    </div>
                                </div>
                                <div className="flex flex-wrap items-center gap-2 sm:justify-end">
                                    {state?.filled && (
                                        <span className="flex gap-1">
                                            <Badge variant="secondary">
                                                H {state.counts.H}
                                            </Badge>
                                            <Badge variant="outline">
                                                I {state.counts.I}
                                            </Badge>
                                            <Badge variant="outline">
                                                S {state.counts.S}
                                            </Badge>
                                            <Badge
                                                variant={
                                                    state.counts.T > 0
                                                        ? 'destructive'
                                                        : 'outline'
                                                }
                                            >
                                                T {state.counts.T}
                                            </Badge>
                                        </span>
                                    )}
                                    {canFill && !empty && (
                                        <Button
                                            size="sm"
                                            variant={
                                                state?.filled
                                                    ? 'outline'
                                                    : 'default'
                                            }
                                            asChild
                                        >
                                            <Link href={href}>
                                                {state?.filled
                                                    ? 'Ubah'
                                                    : 'Isi absen'}
                                            </Link>
                                        </Button>
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}
