import { router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import type { AttendanceKindRow } from '@/components/dormitory/attendance-kinds';
import { Empty } from '@/components/dormitory/facility-forms';
import type { Option } from '@/components/dormitory/types';
import { selectClass } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type Counts = { H: number; I: number; S: number; T: number; total: number };

export type AttendanceRecapProps = {
    month: string;
    months: Option[];
    kind: string | null;
    room: string | null;
    rooms: Option[];
    data: {
        people: (Counts & {
            membership_id: string;
            name: string;
            category_label: string;
            rooms: string;
            percent: number | null;
        })[];
        rooms: {
            id: string;
            code: string;
            building: string;
            filled: number;
            expected: number;
        }[];
        totals: Counts & { percent: number | null };
        days: number;
        kinds: number;
    };
};

const percentFormat = new Intl.NumberFormat('id-ID', {
    maximumFractionDigits: 1,
});
const percentText = (value: number | null) =>
    value === null ? '–' : `${percentFormat.format(value)}%`;

/**
 * Rekap absen asrama bulanan (PRD-008 §22.3): per orang H/I/S/T + % hadir (Hadir ÷ sesi tercatat), dan sesi
 * yang sudah diisi per kamar. Dipakai halaman Absen asrama (tab Rekap) dan Kamar saya → Rekap absen.
 */
export function AttendanceRecapView({
    recap,
    kinds,
    url,
    exportUrl,
    extraQuery = {},
}: {
    recap: AttendanceRecapProps;
    kinds: AttendanceKindRow[];
    /** Alamat halaman (tanpa query) untuk mengganti saringan. */
    url: string;
    exportUrl: string;
    /** Query tetap (mis. asrama, tab). */
    extraQuery?: Record<string, string>;
}) {
    const query = (overrides: Record<string, string | null>) => {
        const merged: Record<string, string | null> = {
            ...extraQuery,
            bulan: recap.month,
            jenis: recap.kind,
            kamar: recap.room,
            ...overrides,
        };

        return Object.fromEntries(
            Object.entries(merged).filter((entry): entry is [string, string] =>
                Boolean(entry[1]),
            ),
        );
    };

    const visit = (overrides: Record<string, string | null>) =>
        router.get(url, query(overrides), {
            preserveScroll: true,
            preserveState: true,
        });

    const { data } = recap;

    return (
        <section className="flex flex-col gap-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div className="grid flex-1 gap-3 sm:grid-cols-3">
                    <div className="grid gap-1.5">
                        <Label htmlFor="rk-month">Bulan</Label>
                        <select
                            id="rk-month"
                            className={selectClass}
                            value={recap.month}
                            onChange={(event) =>
                                visit({ bulan: event.target.value })
                            }
                        >
                            {recap.months.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="rk-kind">Jenis absen</Label>
                        <select
                            id="rk-kind"
                            className={selectClass}
                            value={recap.kind ?? ''}
                            onChange={(event) =>
                                visit({ jenis: event.target.value || null })
                            }
                        >
                            <option value="">Semua jenis</option>
                            {kinds.map((row) => (
                                <option key={row.id} value={row.id}>
                                    {row.name}
                                    {row.is_active ? '' : ' (nonaktif)'}
                                </option>
                            ))}
                        </select>
                    </div>
                    {recap.rooms.length > 0 && (
                        <div className="grid gap-1.5">
                            <Label htmlFor="rk-room">Kamar</Label>
                            <select
                                id="rk-room"
                                className={selectClass}
                                value={recap.room ?? ''}
                                onChange={(event) =>
                                    visit({
                                        kamar: event.target.value || null,
                                    })
                                }
                            >
                                <option value="">Semua kamar</option>
                                {recap.rooms.map((option) => (
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
                </div>
                <Button variant="outline" asChild className="shrink-0">
                    <a href={`${exportUrl}?${new URLSearchParams(query({}))}`}>
                        <Download />
                        Unduh .xlsx
                    </a>
                </Button>
            </div>

            <p className="text-xs text-muted-foreground">
                % hadir = Hadir ÷ sesi tercatat. Hari tanpa absen dihitung belum
                diisi, bukan Tidak ada.
            </p>

            {data.people.length === 0 ? (
                <Empty>Belum ada absen tercatat pada bulan ini.</Empty>
            ) : (
                <div className="overflow-x-auto rounded-xl border bg-card shadow-card">
                    <table className="w-full min-w-[40rem] text-sm tabular-nums">
                        <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                            <tr>
                                <th className="px-3 py-2 font-medium">Nama</th>
                                <th className="px-3 py-2 font-medium">Kamar</th>
                                <th className="px-3 py-2 text-right font-medium">
                                    H
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    I
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    S
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    T
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Sesi
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    % hadir
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.people.map((person) => (
                                <tr
                                    key={person.membership_id}
                                    className="border-t"
                                >
                                    <td className="px-3 py-2">
                                        <span className="font-medium">
                                            {person.name}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {person.category_label}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2">
                                        {person.rooms}
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        {person.H}
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        {person.I}
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        {person.S}
                                    </td>
                                    <td
                                        className={
                                            'px-3 py-2 text-right ' +
                                            (person.T > 0
                                                ? 'font-semibold text-red-700 dark:text-red-400'
                                                : '')
                                        }
                                    >
                                        {person.T}
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        {person.total}
                                    </td>
                                    <td className="px-3 py-2 text-right font-medium">
                                        {percentText(person.percent)}
                                    </td>
                                </tr>
                            ))}
                            <tr className="border-t bg-muted/30 font-semibold">
                                <td className="px-3 py-2" colSpan={2}>
                                    Jumlah
                                </td>
                                <td className="px-3 py-2 text-right">
                                    {data.totals.H}
                                </td>
                                <td className="px-3 py-2 text-right">
                                    {data.totals.I}
                                </td>
                                <td className="px-3 py-2 text-right">
                                    {data.totals.S}
                                </td>
                                <td className="px-3 py-2 text-right">
                                    {data.totals.T}
                                </td>
                                <td className="px-3 py-2 text-right">
                                    {data.totals.total}
                                </td>
                                <td className="px-3 py-2 text-right">
                                    {percentText(data.totals.percent)}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            )}

            {data.rooms.length > 0 && (
                <div className="flex flex-col gap-2">
                    <h3 className="text-sm font-medium">
                        Sesi diisi per kamar{' '}
                        <span className="font-normal text-muted-foreground">
                            ({data.days} hari × {data.kinds} jenis absen)
                        </span>
                    </h3>
                    <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        {data.rooms.map((room) => (
                            <li
                                key={room.id}
                                className="flex items-center justify-between rounded-lg border bg-card px-3 py-2 text-sm"
                            >
                                <span>
                                    <span className="font-medium">
                                        {room.code}
                                    </span>{' '}
                                    <span className="text-xs text-muted-foreground">
                                        {room.building}
                                    </span>
                                </span>
                                <span
                                    className={
                                        'tabular-nums ' +
                                        (room.filled < room.expected
                                            ? 'text-amber-700 dark:text-amber-400'
                                            : 'text-muted-foreground')
                                    }
                                >
                                    {room.filled}/{room.expected}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}
