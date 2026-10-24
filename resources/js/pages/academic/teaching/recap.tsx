import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Download } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type RecapRow = {
    employee_id: string | null;
    name: string;
    scheduled: number;
    taught: number;
    substituted: number;
    empty: number;
    unrecorded: number;
    substituting: number;
    percent: number | null;
};

type Props = {
    organizations: Option[];
    organization: Option;
    month: string;
    maxMonth: string;
    label: string;
    /** Batas hitung (hari ini untuk bulan berjalan). */
    until: string | null;
    rows: RecapRow[];
    totals: RecapRow;
};

const BASE_URL = '/akademik/rekap-mengajar';

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

const COLUMNS: { key: keyof RecapRow; label: string }[] = [
    { key: 'scheduled', label: 'Terjadwal' },
    { key: 'taught', label: 'Mengajar' },
    { key: 'substituted', label: 'Digantikan' },
    { key: 'empty', label: 'Kosong' },
    { key: 'unrecorded', label: 'Belum dicatat' },
    { key: 'substituting', label: 'Menggantikan' },
];

function percent(value: number | null): string {
    return value === null ? '–' : `${value.toLocaleString('id-ID')}%`;
}

/**
 * Rekap presensi mengajar bulanan per guru (PRD-002 §20.4, AC-6c). Pemilik izin kelola presensi
 * mengajar di lembaga itu; dihitung sampai hari ini untuk bulan berjalan.
 */
export default function TeachingRecap(props: Props) {
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

    return (
        <>
            <Head title="Rekap presensi mengajar" />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
                <Button
                    variant="ghost"
                    size="sm"
                    asChild
                    className="self-start"
                >
                    <Link
                        href={`/akademik/presensi-mengajar?lembaga=${props.organization.value}`}
                    >
                        <ArrowLeft />
                        Presensi mengajar
                    </Link>
                </Button>

                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <Heading
                        title="Rekap presensi mengajar"
                        description="Jumlah pertemuan per guru dalam sebulan: mengajar, digantikan, kosong, belum dicatat, dan berapa kali menggantikan guru lain."
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
                    {props.until && props.month === props.maxMonth
                        ? ` — dihitung sampai hari ini. Pertemuan yang belum terjadi tidak dihitung.`
                        : ''}{' '}
                    Persentase = mengajar ÷ terjadwal.
                </p>

                {props.rows.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Tidak ada pertemuan terjadwal di lembaga ini pada bulan
                        tersebut.
                    </p>
                ) : (
                    <>
                        {/* Layar lebar: tabel. */}
                        <div className="hidden overflow-x-auto rounded-xl border md:block">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted/40">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">
                                            Guru
                                        </th>
                                        {COLUMNS.map((column) => (
                                            <th
                                                key={column.key}
                                                className="px-3 py-2 text-right font-medium"
                                            >
                                                {column.label}
                                            </th>
                                        ))}
                                        <th className="px-3 py-2 text-right font-medium">
                                            % Mengajar
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {[...props.rows, props.totals].map(
                                        (row, index) => (
                                            <tr
                                                key={
                                                    row.employee_id ??
                                                    `x-${index}`
                                                }
                                                className={
                                                    index === props.rows.length
                                                        ? 'bg-muted/40 font-medium'
                                                        : ''
                                                }
                                            >
                                                <td className="px-3 py-2">
                                                    {row.name}
                                                </td>
                                                {COLUMNS.map((column) => (
                                                    <td
                                                        key={column.key}
                                                        className="px-3 py-2 text-right tabular-nums"
                                                    >
                                                        {row[column.key]}
                                                    </td>
                                                ))}
                                                <td className="px-3 py-2 text-right tabular-nums">
                                                    {percent(row.percent)}
                                                </td>
                                            </tr>
                                        ),
                                    )}
                                </tbody>
                            </table>
                        </div>

                        {/* HP: kartu per guru. */}
                        <ul className="space-y-3 md:hidden">
                            {[...props.rows, props.totals].map((row, index) => (
                                <li
                                    key={row.employee_id ?? `x-${index}`}
                                    className={
                                        'space-y-2 rounded-xl border p-3 text-sm ' +
                                        (index === props.rows.length
                                            ? 'bg-muted/40'
                                            : '')
                                    }
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <p className="min-w-0 font-medium break-words">
                                            {row.name}
                                        </p>
                                        <span className="shrink-0 tabular-nums">
                                            {percent(row.percent)}
                                        </span>
                                    </div>
                                    <dl className="grid grid-cols-3 gap-2">
                                        {COLUMNS.map((column) => (
                                            <div key={column.key}>
                                                <dt className="text-xs text-muted-foreground">
                                                    {column.label}
                                                </dt>
                                                <dd className="tabular-nums">
                                                    {row[column.key]}
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
