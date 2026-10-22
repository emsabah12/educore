import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, CircleAlert, Download } from 'lucide-react';
import { selectClass } from '@/components/academic/student-actions';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type Subject = {
    id: string;
    name: string;
    code: string | null;
    status: 'BELUM' | 'DRAFT' | 'FINAL';
    kkm: number;
};

type Student = {
    enrollment_id: string;
    name: string;
    gender: string | null;
    nisn: string | null;
    student_number: string | null;
    left: boolean;
    grades: Record<string, number | null>;
    average: number | null;
    below: number;
};

type Props = {
    class: { id: string; name: string; organization: string; year: string };
    terms: Option[];
    term: { value: string; label: string; start: string; end: string } | null;
    ledger: {
        subjects: Subject[];
        students: Student[];
        subject_averages: Record<string, number | null>;
        class_average: number | null;
        final_count: number;
    } | null;
    /** Awalan tautan lembar nilai per mapel; null = tanpa tautan (wali kelas). */
    sheetBase: string | null;
    baseUrl: string;
    backUrl: string;
    backLabel: string;
};

function formatNumber(value: number | null): string {
    return value === null ? '—' : value.toLocaleString('id-ID');
}

/** Singkatan judul kolom: kode mapel bila ada, selain itu nama. */
function shortName(subject: Subject): string {
    return subject.code ?? subject.name;
}

const STATUS_LABEL: Record<Subject['status'], string> = {
    FINAL: 'Final',
    DRAFT: 'Draf',
    BELUM: 'Belum ada',
};

/**
 * Leger nilai kelas per semester (PRD-002 §19 AC-5c): nilai akhir tiap mapel per siswa, rata-rata,
 * dan jumlah nilai di bawah KKM. Nilai dari lembar yang belum final ditandai.
 */
export default function GradeLedger({
    class: item,
    terms,
    term,
    ledger,
    sheetBase,
    baseUrl,
    backUrl,
    backLabel,
}: Props) {
    const pending =
        ledger?.subjects.filter((subject) => subject.status !== 'FINAL') ?? [];

    return (
        <>
            <Head title={`Leger nilai ${item.name}`} />

            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-4 p-4">
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
                    title={`Leger nilai ${item.name}`}
                    description={`${item.organization} · Tahun ajaran ${item.year}`}
                />

                {term === null || ledger === null ? (
                    <Notice>
                        Tahun ajaran kelas ini belum punya semester. Atur
                        semesternya di Kalender akademik.
                    </Notice>
                ) : (
                    <>
                        <div className="grid grid-cols-[repeat(auto-fit,minmax(14rem,1fr))] items-end gap-3 rounded-xl border p-4">
                            <div className="grid gap-1.5">
                                <Label htmlFor="ledger-term">Semester</Label>
                                <select
                                    id="ledger-term"
                                    className={selectClass}
                                    value={term.value}
                                    onChange={(event) =>
                                        router.get(
                                            baseUrl,
                                            { semester: event.target.value },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    {terms.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="flex flex-wrap items-center gap-1.5 text-sm">
                                <Badge variant="outline">
                                    {ledger.final_count} dari{' '}
                                    {ledger.subjects.length} mapel final
                                </Badge>
                                <Badge variant="outline">
                                    Rata-rata kelas{' '}
                                    {formatNumber(ledger.class_average)}
                                </Badge>
                            </div>
                            {ledger.students.length > 0 &&
                                ledger.subjects.length > 0 && (
                                    <Button variant="outline" asChild>
                                        {/* Unduhan file: tautan biasa, bukan navigasi Inertia. */}
                                        <a
                                            href={`${baseUrl}/unduh?semester=${term.value}`}
                                        >
                                            <Download />
                                            Unduh leger (.xlsx)
                                        </a>
                                    </Button>
                                )}
                        </div>

                        {pending.length > 0 && (
                            <Notice>
                                Belum final: {pending.length} mapel (
                                {pending
                                    .map(
                                        (subject) =>
                                            `${subject.name} — ${STATUS_LABEL[subject.status].toLowerCase()}`,
                                    )
                                    .join(', ')}
                                ). Nilai dari lembar draf ditandai * dan belum
                                masuk rapor.
                            </Notice>
                        )}

                        {ledger.subjects.length === 0 ||
                        ledger.students.length === 0 ? (
                            <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                                {ledger.subjects.length === 0
                                    ? 'Kelas ini belum punya mata pelajaran.'
                                    : 'Belum ada siswa di kelas ini pada semester tersebut.'}
                            </p>
                        ) : (
                            <>
                                <DesktopTable
                                    ledger={ledger}
                                    sheetBase={sheetBase}
                                    termId={term.value}
                                />
                                <MobileCards ledger={ledger} />
                            </>
                        )}

                        <p className="text-xs text-muted-foreground">
                            Angka merah = di bawah KKM mapel itu. * = lembar
                            nilai masih draf. Rata-rata dihitung dari nilai
                            akhir yang sudah ada.
                        </p>
                    </>
                )}
            </div>
        </>
    );
}

function Score({ value, subject }: { value: number | null; subject: Subject }) {
    if (value === null) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <span
            className={
                value < subject.kkm
                    ? 'font-medium text-red-600 dark:text-red-400'
                    : undefined
            }
        >
            {value}
            {subject.status !== 'FINAL' && '*'}
        </span>
    );
}

/** Tabel lebar (layar besar): bisa digeser di dalam kotaknya bila mapel banyak. */
function DesktopTable({
    ledger,
    sheetBase,
    termId,
}: {
    ledger: NonNullable<Props['ledger']>;
    sheetBase: string | null;
    termId: string;
}) {
    return (
        <div className="hidden overflow-x-auto rounded-xl border md:block">
            <table className="w-full text-sm tabular-nums">
                <thead className="bg-muted/40 text-xs text-muted-foreground">
                    <tr>
                        <th className="sticky left-0 bg-muted px-3 py-2 text-left font-medium">
                            Siswa
                        </th>
                        {ledger.subjects.map((subject) => (
                            <th
                                key={subject.id}
                                className="px-2 py-2 text-center font-medium"
                                title={`${subject.name} · KKM ${subject.kkm} · ${STATUS_LABEL[subject.status]}`}
                            >
                                {sheetBase ? (
                                    <Link
                                        href={`${sheetBase}/${subject.id}/nilai?semester=${termId}`}
                                        className="underline-offset-2 hover:underline"
                                    >
                                        {shortName(subject)}
                                    </Link>
                                ) : (
                                    shortName(subject)
                                )}
                                <span className="block font-normal">
                                    KKM {subject.kkm}
                                </span>
                            </th>
                        ))}
                        <th className="px-2 py-2 text-center font-medium">
                            Rata-rata
                        </th>
                        <th className="px-2 py-2 text-center font-medium">
                            &lt; KKM
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {ledger.students.map((student, index) => (
                        <tr key={student.enrollment_id}>
                            <td className="sticky left-0 bg-background px-3 py-2">
                                <span className="text-muted-foreground">
                                    {index + 1}.
                                </span>{' '}
                                {student.name}
                                {student.left && (
                                    <span className="text-xs text-muted-foreground">
                                        {' '}
                                        (keluar)
                                    </span>
                                )}
                            </td>
                            {ledger.subjects.map((subject) => (
                                <td
                                    key={subject.id}
                                    className="px-2 py-2 text-center"
                                >
                                    <Score
                                        value={
                                            student.grades[subject.id] ?? null
                                        }
                                        subject={subject}
                                    />
                                </td>
                            ))}
                            <td className="px-2 py-2 text-center font-medium">
                                {formatNumber(student.average)}
                            </td>
                            <td className="px-2 py-2 text-center">
                                {student.below > 0 ? (
                                    <span className="font-medium text-red-600 dark:text-red-400">
                                        {student.below}
                                    </span>
                                ) : (
                                    0
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
                <tfoot className="border-t bg-muted/30 font-medium">
                    <tr>
                        <td className="sticky left-0 bg-muted px-3 py-2">
                            Rata-rata mapel
                        </td>
                        {ledger.subjects.map((subject) => (
                            <td
                                key={subject.id}
                                className="px-2 py-2 text-center"
                            >
                                {formatNumber(
                                    ledger.subject_averages[subject.id] ?? null,
                                )}
                            </td>
                        ))}
                        <td className="px-2 py-2 text-center">
                            {formatNumber(ledger.class_average)}
                        </td>
                        <td />
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}

/** HP: satu kartu per siswa, nilai mapel tersusun dalam grid. */
function MobileCards({ ledger }: { ledger: NonNullable<Props['ledger']> }) {
    return (
        <ul className="space-y-2 md:hidden">
            {ledger.students.map((student, index) => (
                <li
                    key={student.enrollment_id}
                    className="space-y-2 rounded-xl border p-3 text-sm"
                >
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <p className="font-medium break-words">
                            {index + 1}. {student.name}
                            {student.left && (
                                <span className="text-xs font-normal text-muted-foreground">
                                    {' '}
                                    (keluar)
                                </span>
                            )}
                        </p>
                        <span className="text-muted-foreground tabular-nums">
                            Rata-rata {formatNumber(student.average)}
                            {student.below > 0 && (
                                <span className="text-red-600 dark:text-red-400">
                                    {' '}
                                    · {student.below} &lt; KKM
                                </span>
                            )}
                        </span>
                    </div>
                    <dl className="grid grid-cols-[repeat(auto-fill,minmax(6rem,1fr))] gap-1.5 tabular-nums">
                        {ledger.subjects.map((subject) => (
                            <div
                                key={subject.id}
                                className="rounded-md bg-muted/50 px-2 py-1"
                            >
                                <dt
                                    className="truncate text-xs text-muted-foreground"
                                    title={subject.name}
                                >
                                    {shortName(subject)}
                                </dt>
                                <dd>
                                    <Score
                                        value={
                                            student.grades[subject.id] ?? null
                                        }
                                        subject={subject}
                                    />
                                </dd>
                            </div>
                        ))}
                    </dl>
                </li>
            ))}
        </ul>
    );
}

function Notice({ children }: { children: React.ReactNode }) {
    return (
        <p className="flex gap-2 rounded-md border border-amber-500/50 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
            <CircleAlert className="mt-0.5 size-4 shrink-0" />
            <span>{children}</span>
        </p>
    );
}
