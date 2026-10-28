import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';

type Option = { value: string; label: string };

type Day = {
    date: string;
    status: 'H' | 'S' | 'I' | 'A';
    label: string;
    subject: string | null;
    time: string | null;
};

type Props = {
    student: { id: string; name: string };
    classes: Option[];
    classId: string | null;
    months: Option[];
    month: string | null;
    attendance: {
        kind: 'hari' | 'pertemuan';
        totals: { H: number; S: number; I: number; A: number };
        days: Day[];
    } | null;
};

const dayFormat = new Intl.DateTimeFormat('id-ID', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
});

function formatDay(value: string): string {
    const [year, month, day] = value.split('-').map(Number);

    return dayFormat.format(new Date(year, month - 1, day));
}

const STATUS_STYLE: Record<Day['status'], string> = {
    H: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100',
    S: 'bg-sky-100 text-sky-900 dark:bg-sky-950 dark:text-sky-100',
    I: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-100',
    A: 'bg-red-100 text-red-900 dark:bg-red-950 dark:text-red-100',
};

/**
 * Presensi anak per bulan (PRD-002 §21.3, AC-7b): rekap H/S/I/A + rincian. Hanya baca.
 */
export default function ChildAttendance({
    student,
    classes,
    classId,
    months,
    month,
    attendance,
}: Props) {
    const visit = (params: Record<string, string>) =>
        router.get(`/anak-saya/${student.id}/presensi`, params, {
            preserveScroll: true,
        });

    return (
        <>
            <Head title={`Presensi — ${student.name}`} />

            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 p-4">
                <Button
                    asChild
                    variant="ghost"
                    size="sm"
                    className="self-start"
                >
                    <Link href={`/anak-saya/${student.id}`}>
                        <ArrowLeft />
                        {student.name}
                    </Link>
                </Button>

                <Heading
                    title="Presensi"
                    description={`Kehadiran ${student.name} per bulan, sesuai yang dicatat sekolah.`}
                />

                {classes.length === 0 ? (
                    <Empty>Anak ini belum pernah masuk kelas.</Empty>
                ) : (
                    <>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Select
                                id="kelas"
                                label="Kelas"
                                value={classId ?? ''}
                                options={classes}
                                onChange={(value) => visit({ kelas: value })}
                            />
                            <Select
                                id="bulan"
                                label="Bulan"
                                value={month ?? ''}
                                options={months}
                                onChange={(value) =>
                                    visit({
                                        kelas: classId ?? '',
                                        bulan: value,
                                    })
                                }
                            />
                        </div>

                        {attendance === null ? (
                            <Empty>Tahun ajaran kelas ini belum dimulai.</Empty>
                        ) : (
                            <>
                                <dl className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                    {(['H', 'S', 'I', 'A'] as const).map(
                                        (code) => (
                                            <div
                                                key={code}
                                                className={`rounded-xl p-3 ${STATUS_STYLE[code]}`}
                                            >
                                                <dt className="text-xs">
                                                    {
                                                        {
                                                            H: 'Hadir',
                                                            S: 'Sakit',
                                                            I: 'Izin',
                                                            A: 'Alpa',
                                                        }[code]
                                                    }
                                                </dt>
                                                <dd className="text-2xl font-semibold tabular-nums">
                                                    {attendance.totals[code]}
                                                </dd>
                                            </div>
                                        ),
                                    )}
                                </dl>
                                <p className="text-xs text-muted-foreground">
                                    Dihitung per{' '}
                                    {attendance.kind === 'pertemuan'
                                        ? 'pertemuan pelajaran'
                                        : 'hari sekolah'}
                                    . Hari yang belum diisi sekolah tidak
                                    ditampilkan.
                                </p>

                                {attendance.days.length === 0 ? (
                                    <Empty>
                                        Belum ada presensi tercatat di bulan
                                        ini.
                                    </Empty>
                                ) : (
                                    <ul className="divide-y rounded-xl border">
                                        {attendance.days.map((day, index) => (
                                            <li
                                                key={`${day.date}-${day.time ?? ''}-${index}`}
                                                className="flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-sm"
                                            >
                                                <span className="min-w-0 break-words">
                                                    {formatDay(day.date)}
                                                    {day.subject && (
                                                        <span className="block text-muted-foreground">
                                                            {day.time} ·{' '}
                                                            {day.subject}
                                                        </span>
                                                    )}
                                                </span>
                                                <span
                                                    className={`rounded-md px-2 py-0.5 text-xs font-medium ${STATUS_STYLE[day.status]}`}
                                                >
                                                    {day.label}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

function Select({
    id,
    label,
    value,
    options,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    options: Option[];
    onChange: (value: string) => void;
}) {
    return (
        <label htmlFor={id} className="grid min-w-0 gap-1 text-sm">
            <span className="text-muted-foreground">{label}</span>
            <select
                id={id}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="h-9 w-full min-w-0 rounded-md border bg-background px-2 text-sm"
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </label>
    );
}

function Empty({ children }: { children: React.ReactNode }) {
    return (
        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
            {children}
        </p>
    );
}
