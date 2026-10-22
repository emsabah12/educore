import { Form, Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, CircleAlert, Printer, Save } from 'lucide-react';
import { useState } from 'react';
import { selectClass } from '@/components/academic/student-actions';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type Student = {
    enrollment_id: string;
    name: string;
    nisn: string | null;
    left: boolean;
    graded: number;
    average: number | null;
    attendance: { S: number; I: number; A: number };
    note: string | null;
};

type Props = {
    class: { id: string; name: string; organization: string; year: string };
    terms: Option[];
    term: { value: string; label: string; start: string; end: string } | null;
    students: Student[];
    pending: string[];
    finalSubjects: number;
    canEditNotes: boolean;
    noteMax: number;
    baseUrl: string;
    backUrl: string;
    backLabel: string;
};

/**
 * Rapor kelas per semester (PRD-002 §19 AC-5d): ringkasan tiap siswa, catatan wali kelas, dan
 * tautan cetak (satu siswa atau sekelas). Rapor hanya memuat mapel yang nilainya sudah final.
 */
export default function ReportIndex(props: Props) {
    const { class: item, terms, term, students, pending, baseUrl } = props;

    return (
        <>
            <Head title={`Rapor ${item.name}`} />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
                <div>
                    <Link
                        href={props.backUrl}
                        className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        {props.backLabel}
                    </Link>
                </div>

                <Heading
                    title={`Rapor ${item.name}`}
                    description={`${item.organization} · Tahun ajaran ${item.year}`}
                />

                {term === null ? (
                    <Notice>
                        Tahun ajaran kelas ini belum punya semester. Atur
                        semesternya di Kalender akademik.
                    </Notice>
                ) : (
                    <>
                        <div className="grid grid-cols-[repeat(auto-fit,minmax(14rem,1fr))] items-end gap-3 rounded-xl border p-4">
                            <div className="grid gap-1.5">
                                <Label htmlFor="report-term">Semester</Label>
                                <select
                                    id="report-term"
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
                            <p className="text-sm">
                                <Badge variant="outline">
                                    {props.finalSubjects} mapel final masuk
                                    rapor
                                </Badge>
                            </p>
                            {students.length > 0 && (
                                <Button asChild>
                                    <Link
                                        href={`${baseUrl}/cetak?semester=${term.value}`}
                                    >
                                        <Printer />
                                        Cetak semua rapor
                                    </Link>
                                </Button>
                            )}
                        </div>

                        {pending.length > 0 && (
                            <Notice>
                                Belum masuk rapor karena nilainya belum final:{' '}
                                {pending.join(', ')}.
                            </Notice>
                        )}

                        {students.length === 0 ? (
                            <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                                Belum ada siswa di kelas ini pada semester
                                tersebut.
                            </p>
                        ) : (
                            <NotesForm
                                key={`${term.value}-${students.map((student) => student.note ?? '').join('|')}`}
                                {...props}
                                term={term}
                            />
                        )}
                    </>
                )}
            </div>
        </>
    );
}

function NotesForm({
    students,
    term,
    canEditNotes,
    noteMax,
    baseUrl,
}: Props & { term: NonNullable<Props['term']> }) {
    const [notes, setNotes] = useState<Record<string, string>>(() =>
        Object.fromEntries(
            students.map((student) => [
                student.enrollment_id,
                student.note ?? '',
            ]),
        ),
    );

    const list = (
        <ul className="divide-y rounded-xl border">
            {students.map((student, index) => (
                <li key={student.enrollment_id} className="space-y-2 p-4">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <div className="min-w-0 text-sm">
                            <p className="font-medium break-words">
                                {index + 1}. {student.name}
                                {student.left && (
                                    <span className="font-normal text-muted-foreground">
                                        {' '}
                                        (keluar)
                                    </span>
                                )}
                            </p>
                            <p className="text-muted-foreground tabular-nums">
                                {student.graded} mapel bernilai · rata-rata{' '}
                                {student.average === null
                                    ? '—'
                                    : student.average.toLocaleString(
                                          'id-ID',
                                      )}{' '}
                                · S {student.attendance.S} · I{' '}
                                {student.attendance.I} · A{' '}
                                {student.attendance.A}
                            </p>
                        </div>
                        <Button size="sm" variant="outline" asChild>
                            <Link
                                href={`${baseUrl}/cetak?semester=${term.value}&siswa=${student.enrollment_id}`}
                            >
                                <Printer />
                                Lihat / cetak
                            </Link>
                        </Button>
                    </div>

                    {canEditNotes ? (
                        <textarea
                            aria-label={`Catatan wali kelas untuk ${student.name}`}
                            name={`notes[${student.enrollment_id}]`}
                            rows={2}
                            maxLength={noteMax}
                            placeholder="Catatan wali kelas (opsional)"
                            value={notes[student.enrollment_id]}
                            onChange={(event) =>
                                setNotes((current) => ({
                                    ...current,
                                    [student.enrollment_id]: event.target.value,
                                }))
                            }
                            className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                        />
                    ) : (
                        student.note && (
                            <p className="text-sm break-words whitespace-pre-line text-muted-foreground">
                                Catatan: {student.note}
                            </p>
                        )
                    )}
                </li>
            ))}
        </ul>
    );

    if (!canEditNotes) {
        return list;
    }

    return (
        <Form
            action={`${baseUrl}/catatan`}
            method="post"
            errorBag="reportNotes"
            options={{ preserveScroll: true }}
            className="space-y-3"
        >
            {({ processing, errors }) => (
                <>
                    <input type="hidden" name="semester" value={term.value} />
                    {list}
                    <InputError
                        message={
                            errors.notes ??
                            errors.form ??
                            Object.entries(errors).find(([field]) =>
                                field.startsWith('notes.'),
                            )?.[1]
                        }
                    />
                    <div className="sticky bottom-0 border-t bg-background py-3">
                        <Button
                            type="submit"
                            disabled={processing}
                            className="w-full sm:w-auto"
                        >
                            <Save />
                            {processing ? 'Menyimpan…' : 'Simpan catatan'}
                        </Button>
                    </div>
                </>
            )}
        </Form>
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
