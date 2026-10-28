import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';

type Option = { value: string; label: string };

type Line = {
    name: string;
    kkm: number;
    final: number | null;
    predicate: string | null;
    description: string | null;
};

type Props = {
    student: { id: string; name: string };
    classes: Option[];
    classId: string | null;
    terms: Option[];
    term: { value: string; label: string } | null;
    subjects: Line[];
    average: number | null;
    attendance: { S: number; I: number; A: number } | null;
    attendanceKind: 'hari' | 'pertemuan';
    pending: string[];
    canPrint: boolean;
};

/**
 * Nilai & rapor anak (PRD-002 §21.3, AC-7b): hanya nilai yang sudah FINAL; rapor bisa dicetak bila
 * semua mapel sudah final. Hanya baca.
 */
export default function ChildGrades({
    student,
    classes,
    classId,
    terms,
    term,
    subjects,
    average,
    attendance,
    attendanceKind,
    pending,
    canPrint,
}: Props) {
    const visit = (params: Record<string, string>) =>
        router.get(`/anak-saya/${student.id}/nilai`, params, {
            preserveScroll: true,
        });

    return (
        <>
            <Head title={`Nilai & rapor — ${student.name}`} />

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
                    title="Nilai & rapor"
                    description="Nilai yang tampil hanya yang sudah difinalkan guru."
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
                                id="semester"
                                label="Semester"
                                value={term?.value ?? ''}
                                options={terms}
                                onChange={(value) =>
                                    visit({
                                        kelas: classId ?? '',
                                        semester: value,
                                    })
                                }
                            />
                        </div>

                        {term === null ? (
                            <Empty>Semester belum ditetapkan.</Empty>
                        ) : (
                            <>
                                {subjects.length === 0 ? (
                                    <Empty>
                                        Belum ada nilai yang difinalkan di
                                        semester ini.
                                    </Empty>
                                ) : (
                                    <ul className="divide-y rounded-xl border">
                                        {subjects.map((line) => (
                                            <li
                                                key={line.name}
                                                className="space-y-1 px-4 py-3"
                                            >
                                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                                    <span className="font-medium break-words">
                                                        {line.name}
                                                    </span>
                                                    <span className="text-sm tabular-nums">
                                                        {line.final === null ? (
                                                            <span className="text-muted-foreground">
                                                                Belum ada nilai
                                                            </span>
                                                        ) : (
                                                            <>
                                                                <span
                                                                    className={
                                                                        'text-lg font-semibold ' +
                                                                        (line.final <
                                                                        line.kkm
                                                                            ? 'text-red-700 dark:text-red-400'
                                                                            : '')
                                                                    }
                                                                >
                                                                    {line.final}
                                                                </span>{' '}
                                                                {line.predicate}
                                                            </>
                                                        )}
                                                    </span>
                                                </div>
                                                <p className="text-xs text-muted-foreground">
                                                    KKM {line.kkm}
                                                    {line.description &&
                                                        ` · ${line.description}`}
                                                </p>
                                            </li>
                                        ))}
                                    </ul>
                                )}

                                <dl className="grid gap-3 rounded-xl border p-4 sm:grid-cols-2">
                                    <div>
                                        <dt className="text-xs text-muted-foreground">
                                            Rata-rata nilai final
                                        </dt>
                                        <dd className="text-sm">
                                            {average ?? '–'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-muted-foreground">
                                            Ketidakhadiran semester ini (per{' '}
                                            {attendanceKind})
                                        </dt>
                                        <dd className="text-sm">
                                            {attendance
                                                ? `Sakit ${attendance.S} · Izin ${attendance.I} · Alpa ${attendance.A}`
                                                : '–'}
                                        </dd>
                                    </div>
                                </dl>

                                {pending.length > 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        Belum final: {pending.join(', ')}. Rapor
                                        bisa dicetak setelah semua mapel
                                        difinalkan.
                                    </p>
                                )}

                                {canPrint && (
                                    <Button
                                        asChild
                                        className="w-full sm:w-auto"
                                    >
                                        <Link
                                            href={`/anak-saya/${student.id}/rapor?kelas=${classId}&semester=${term.value}`}
                                        >
                                            <Printer />
                                            Lihat & cetak rapor
                                        </Link>
                                    </Button>
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
