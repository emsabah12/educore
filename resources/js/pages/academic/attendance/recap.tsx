import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { selectClass } from '@/components/academic/student-actions';
import Heading from '@/components/heading';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type Row = {
    enrollment_id: string;
    name: string;
    gender: string | null;
    nisn: string | null;
    student_number: string | null;
    H: number;
    S: number;
    I: number;
    A: number;
    total: number;
    percent: number | null;
};

type Props = {
    class: { id: string; name: string; organization: string; year: string };
    periods: Option[];
    period: { value: string; label: string; start: string; end: string };
    recap: {
        days: number;
        students: Row[];
        totals: { H: number; S: number; I: number; A: number };
    };
    baseUrl: string;
    backUrl: string;
    backLabel: string;
};

const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' });

function formatDate(value: string): string {
    const [year, month, day] = value.split('-').map(Number);

    return dateFormat.format(new Date(year, month - 1, day));
}

function formatPercent(value: number | null): string {
    return value === null ? '—' : `${value.toLocaleString('id-ID')}%`;
}

const CODES = ['H', 'S', 'I', 'A'] as const;

/**
 * Rekap presensi harian satu kelas (PRD-002 §17 AC-4b): jumlah H/S/I/A dan persentase hadir per
 * siswa untuk satu periode. Hari yang belum diisi tidak dihitung alpa.
 */
export default function AttendanceRecap({
    class: item,
    periods,
    period,
    recap,
    baseUrl,
    backUrl,
    backLabel,
}: Props) {
    const totalDays =
        recap.totals.H + recap.totals.S + recap.totals.I + recap.totals.A;
    const overall =
        totalDays === 0
            ? null
            : Math.round((recap.totals.H * 1000) / totalDays) / 10;

    return (
        <>
            <Head title={`Rekap presensi ${item.name}`} />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
                <div>
                    <Link
                        href={backUrl}
                        className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        {backLabel}
                    </Link>
                </div>

                <Heading
                    title={`Rekap presensi ${item.name}`}
                    description={`${item.organization} · Tahun ajaran ${item.year}`}
                />

                <div className="grid grid-cols-[repeat(auto-fit,minmax(14rem,1fr))] items-end gap-3 rounded-xl border p-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="rk-period">Periode</Label>
                        <select
                            id="rk-period"
                            className={selectClass}
                            value={period.value}
                            onChange={(event) =>
                                router.get(
                                    baseUrl,
                                    { periode: event.target.value },
                                    { preserveScroll: true, replace: true },
                                )
                            }
                        >
                            {periods.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {formatDate(period.start)} – {formatDate(period.end)} ·{' '}
                        {recap.days} hari sudah diisi. Hari yang belum diisi
                        tidak dihitung alpa.
                    </p>
                </div>

                {recap.students.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Tidak ada siswa di kelas ini pada periode tersebut.
                    </p>
                ) : (
                    <section className="rounded-xl border">
                        <div className="hidden grid-cols-[2.5rem_1fr_repeat(4,3rem)_5rem] gap-2 border-b px-4 py-2 text-xs font-medium text-muted-foreground sm:grid">
                            <span>No</span>
                            <span>Nama</span>
                            <span className="text-center" title="Hadir">
                                H
                            </span>
                            <span className="text-center" title="Sakit">
                                S
                            </span>
                            <span className="text-center" title="Izin">
                                I
                            </span>
                            <span className="text-center" title="Alpa">
                                A
                            </span>
                            <span className="text-right">% hadir</span>
                        </div>
                        <ul className="divide-y">
                            {recap.students.map((row, index) => (
                                <li
                                    key={row.enrollment_id}
                                    className="grid grid-cols-[2.5rem_1fr] gap-2 px-4 py-3 text-sm sm:grid-cols-[2.5rem_1fr_repeat(4,3rem)_5rem] sm:items-center"
                                >
                                    <span className="text-muted-foreground tabular-nums">
                                        {index + 1}.
                                    </span>
                                    <div className="min-w-0">
                                        <p className="font-medium break-words">
                                            {row.name}
                                        </p>
                                        <p className="text-muted-foreground">
                                            {[
                                                row.gender,
                                                row.nisn
                                                    ? `NISN ${row.nisn}`
                                                    : null,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </p>
                                    </div>
                                    {/* HP: angka di bawah nama dalam grid 5 kolom; desktop: kolom tabel. */}
                                    <div className="col-span-2 grid grid-cols-5 gap-1 text-center sm:contents">
                                        {CODES.map((code) => (
                                            <span
                                                key={code}
                                                className="rounded bg-muted/50 py-1 tabular-nums sm:bg-transparent sm:py-0"
                                            >
                                                <span className="mr-1 text-xs text-muted-foreground sm:hidden">
                                                    {code}
                                                </span>
                                                {row[code]}
                                            </span>
                                        ))}
                                        <span className="rounded bg-muted/50 py-1 font-medium tabular-nums sm:bg-transparent sm:py-0 sm:text-right">
                                            {formatPercent(row.percent)}
                                        </span>
                                    </div>
                                </li>
                            ))}
                        </ul>
                        <div className="grid grid-cols-[2.5rem_1fr] gap-2 border-t bg-muted/30 px-4 py-2 text-sm font-medium sm:grid-cols-[2.5rem_1fr_repeat(4,3rem)_5rem]">
                            <span />
                            <span>Jumlah</span>
                            <div className="col-span-2 grid grid-cols-5 gap-1 text-center tabular-nums sm:contents">
                                {CODES.map((code) => (
                                    <span key={code}>
                                        <span className="mr-1 text-xs text-muted-foreground sm:hidden">
                                            {code}
                                        </span>
                                        {recap.totals[code]}
                                    </span>
                                ))}
                                <span className="sm:text-right">
                                    {formatPercent(overall)}
                                </span>
                            </div>
                        </div>
                    </section>
                )}

                <p className="text-xs text-muted-foreground">
                    H = Hadir · S = Sakit · I = Izin · A = Alpa. % hadir = Hadir
                    ÷ hari yang sudah diisi untuk siswa itu.
                </p>
            </div>
        </>
    );
}
