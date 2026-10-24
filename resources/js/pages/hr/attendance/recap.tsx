import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Download } from 'lucide-react';
import Heading from '@/components/heading';
import type { AttendanceCounts } from '@/components/hr/attendance-summary';
import {
    ATTENDANCE_COLUMNS,
    attendanceValue,
} from '@/components/hr/attendance-summary';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type RecapRow = AttendanceCounts & {
    employee_id: string;
    name: string;
    employee_number: string | null;
};

type Props = {
    organizations: Option[];
    organization: Option;
    month: string;
    maxMonth: string;
    label: string;
    /** Batas hitung (hari ini untuk bulan berjalan). */
    until: string;
    rows: RecapRow[];
    totals: AttendanceCounts;
};

const BASE_URL = '/pegawai/presensi/rekap';

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

/**
 * Rekap presensi pegawai bulanan per lembaga (PRD-001 §14.4, HR-4c). Pemilik izin kelola presensi
 * pegawai di lembaga itu.
 */
export default function AttendanceRecapPage(props: Props) {
    const go = (query: { lembaga?: string; bulan?: string }) =>
        router.get(
            BASE_URL,
            {
                lembaga: query.lembaga ?? props.organization.value,
                bulan: query.bulan ?? props.month,
            },
            { preserveState: false, replace: true },
        );

    const exportUrl = `${BASE_URL}/unduh?lembaga=${props.organization.value}&bulan=${props.month}`;
    const all = [
        ...props.rows,
        {
            ...props.totals,
            employee_id: 'jumlah',
            name: 'Jumlah',
            employee_number: null,
        },
    ];

    return (
        <>
            <Head title="Rekap presensi pegawai" />

            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-4 p-4">
                <Button
                    variant="ghost"
                    size="sm"
                    asChild
                    className="self-start"
                >
                    <Link
                        href={`/pegawai/presensi?lembaga=${props.organization.value}`}
                    >
                        <ArrowLeft />
                        Presensi pegawai
                    </Link>
                </Button>

                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <Heading
                        title="Rekap presensi pegawai"
                        description="Jumlah hari wajib, kehadiran, keterlambatan, dan ketidakhadiran per pegawai dalam sebulan."
                    />
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2 sm:flex">
                        <Button variant="outline" asChild>
                            <a href={exportUrl}>
                                <Download />
                                Unduh .xlsx
                            </a>
                        </Button>
                    </div>
                </div>

                <div className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-3 rounded-xl border p-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="lembaga">Lembaga</Label>
                        <select
                            id="lembaga"
                            className={selectClass}
                            value={props.organization.value}
                            onChange={(event) =>
                                go({ lembaga: event.target.value })
                            }
                        >
                            {props.organizations.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="bulan">Bulan</Label>
                        <Input
                            id="bulan"
                            type="month"
                            value={props.month}
                            max={props.maxMonth}
                            onChange={(event) =>
                                event.target.value &&
                                go({ bulan: event.target.value })
                            }
                        />
                    </div>
                </div>

                <p className="text-sm text-muted-foreground">
                    {props.label}
                    {props.month === props.maxMonth &&
                        ' — dihitung sampai hari ini.'}{' '}
                    Cuti dihitung di hari wajib; hari wajib tanpa catatan =
                    Belum diisi.
                </p>

                {props.rows.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Tidak ada pegawai dengan penempatan utama di lembaga ini
                        pada bulan tersebut.
                    </p>
                ) : (
                    <>
                        {/* Layar lebar: tabel. */}
                        <div className="hidden overflow-x-auto rounded-xl border lg:block">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted/40">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">
                                            Pegawai
                                        </th>
                                        {ATTENDANCE_COLUMNS.map((column) => (
                                            <th
                                                key={column.key}
                                                className="px-3 py-2 text-right font-medium"
                                            >
                                                {column.label}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {all.map((row) => (
                                        <tr
                                            key={row.employee_id}
                                            className={
                                                row.employee_id === 'jumlah'
                                                    ? 'bg-muted/40 font-medium'
                                                    : ''
                                            }
                                        >
                                            <td className="px-3 py-2">
                                                {row.name}
                                                {row.employee_number && (
                                                    <span className="block text-xs text-muted-foreground">
                                                        {row.employee_number}
                                                    </span>
                                                )}
                                            </td>
                                            {ATTENDANCE_COLUMNS.map(
                                                (column) => (
                                                    <td
                                                        key={column.key}
                                                        className="px-3 py-2 text-right whitespace-nowrap tabular-nums"
                                                    >
                                                        {attendanceValue(
                                                            row,
                                                            column.key,
                                                        )}
                                                    </td>
                                                ),
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {/* HP & tablet: kartu per pegawai. */}
                        <ul className="space-y-3 lg:hidden">
                            {all.map((row) => (
                                <li
                                    key={row.employee_id}
                                    className={
                                        'space-y-2 rounded-xl border p-3 text-sm ' +
                                        (row.employee_id === 'jumlah'
                                            ? 'bg-muted/40'
                                            : '')
                                    }
                                >
                                    <p className="font-medium break-words">
                                        {row.name}
                                        {row.employee_number &&
                                            ` · ${row.employee_number}`}
                                    </p>
                                    <dl className="grid grid-cols-[repeat(auto-fit,minmax(6.5rem,1fr))] gap-2">
                                        {ATTENDANCE_COLUMNS.map((column) => (
                                            <div key={column.key}>
                                                <dt className="text-xs text-muted-foreground">
                                                    {column.label}
                                                </dt>
                                                <dd className="tabular-nums">
                                                    {attendanceValue(
                                                        row,
                                                        column.key,
                                                    )}
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </div>
        </>
    );
}
