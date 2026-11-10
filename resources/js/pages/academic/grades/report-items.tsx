import { Form, Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, LayoutTemplate, Save } from 'lucide-react';
import { useState } from 'react';
import { selectClass } from '@/components/academic/student-actions';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type Item = {
    id: string;
    kind: 'EKSKUL' | 'KEPRIBADIAN';
    name: string;
    /** Keterangan otomatis per huruf (kepribadian). */
    descriptions: Record<string, string>;
};

type Grade = { letter: string | null; note: string | null };

type Props = {
    class: { id: string; name: string; organization: string; year: string };
    terms: Option[];
    term: { value: string; label: string } | null;
    sheet: {
        items: Item[];
        predicates: { letter: string; label: string | null; min: number }[];
        students: { enrollment_id: string; name: string; left: boolean }[];
        grades: Record<string, Record<string, Grade>>;
    } | null;
    canEdit: boolean;
    noteMax: number;
    baseUrl: string;
    backUrl: string;
    formatUrl: string | null;
};

type Draft = Record<string, Record<string, { letter: string; note: string }>>;

/**
 * Nilai ekstrakurikuler & kepribadian per kelas per semester (PRD-002 §22.6 AC-8d). Huruf dari skala
 * predikat lembaga; ekstrakurikuler kosong = tidak ikut. Keterangan kepribadian otomatis menurut
 * huruf (diatur di Format rapor) dan boleh diganti per siswa.
 */
export default function ReportItems(props: Props) {
    const { class: item, terms, term, sheet, baseUrl } = props;

    return (
        <>
            <Head title={`Ekstrakurikuler & kepribadian ${item.name}`} />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
                <div>
                    <Link
                        href={`${props.backUrl}${term ? `?semester=${term.value}` : ''}`}
                        className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        Kembali ke rapor
                    </Link>
                </div>

                <Heading
                    title={`Ekstrakurikuler & kepribadian · ${item.name}`}
                    description={`${item.organization} · Tahun ajaran ${item.year}`}
                />

                {term === null || sheet === null ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Tahun ajaran kelas ini belum punya semester.
                    </p>
                ) : (
                    <>
                        <div className="grid grid-cols-[repeat(auto-fit,minmax(14rem,1fr))] items-end gap-3 rounded-xl border p-4">
                            <div className="grid gap-1.5">
                                <Label htmlFor="items-term">Semester</Label>
                                <select
                                    id="items-term"
                                    className={selectClass}
                                    value={term.value}
                                    onChange={(event) =>
                                        router.get(
                                            baseUrl,
                                            { semester: event.target.value },
                                            { replace: true },
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
                            {props.formatUrl && (
                                <Button asChild variant="outline">
                                    <Link href={props.formatUrl}>
                                        <LayoutTemplate />
                                        Atur daftar & keterangan
                                    </Link>
                                </Button>
                            )}
                        </div>

                        {sheet.items.length === 0 ? (
                            <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                                Lembaga ini belum punya daftar ekstrakurikuler
                                atau kepribadian.{' '}
                                {props.formatUrl
                                    ? 'Tambahkan lewat tombol "Atur daftar & keterangan".'
                                    : 'Minta operator akademik menambahkannya di Format rapor.'}
                            </p>
                        ) : sheet.students.length === 0 ? (
                            <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                                Belum ada siswa di kelas ini pada semester
                                tersebut.
                            </p>
                        ) : (
                            <ItemsForm
                                key={`${term.value}-${JSON.stringify(sheet.grades)}`}
                                {...props}
                                sheet={sheet}
                                term={term}
                            />
                        )}
                    </>
                )}
            </div>
        </>
    );
}

function ItemsForm({
    sheet,
    term,
    canEdit,
    noteMax,
    baseUrl,
}: Props & {
    sheet: NonNullable<Props['sheet']>;
    term: NonNullable<Props['term']>;
}) {
    const extracurriculars = sheet.items.filter(
        (item) => item.kind === 'EKSKUL',
    );
    const personalities = sheet.items.filter(
        (item) => item.kind === 'KEPRIBADIAN',
    );
    const [values, setValues] = useState<Draft>(() =>
        Object.fromEntries(
            sheet.students.map((student) => [
                student.enrollment_id,
                Object.fromEntries(
                    sheet.items.map((item) => {
                        const grade = sheet.grades[student.enrollment_id]?.[
                            item.id
                        ] ?? { letter: null, note: null };

                        return [
                            item.id,
                            {
                                letter: grade.letter ?? '',
                                note: grade.note ?? '',
                            },
                        ];
                    }),
                ),
            ]),
        ),
    );

    const set = (
        enrollmentId: string,
        itemId: string,
        change: Partial<{ letter: string; note: string }>,
    ) =>
        setValues((current) => ({
            ...current,
            [enrollmentId]: {
                ...current[enrollmentId],
                [itemId]: { ...current[enrollmentId][itemId], ...change },
            },
        }));

    const describe = (letter: string) => {
        const label = sheet.predicates.find(
            (level) => level.letter === letter,
        )?.label;

        return label ? `${letter} ${label}` : letter;
    };

    const letterSelect = (
        enrollmentId: string,
        studentName: string,
        item: Item,
        emptyLabel: string,
    ) => (
        <select
            id={`ri-${enrollmentId}-${item.id}`}
            name={`grades[${enrollmentId}][${item.id}][letter]`}
            aria-label={`${item.name} untuk ${studentName}`}
            className={selectClass}
            value={values[enrollmentId][item.id].letter}
            disabled={!canEdit}
            onChange={(event) =>
                set(enrollmentId, item.id, { letter: event.target.value })
            }
        >
            <option value="">{emptyLabel}</option>
            {sheet.predicates.map((level) => (
                <option key={level.letter} value={level.letter}>
                    {describe(level.letter)}
                </option>
            ))}
        </select>
    );

    return (
        <Form
            action={baseUrl}
            method="post"
            errorBag="reportItems"
            options={{ preserveScroll: true }}
            className="space-y-4"
        >
            {({ processing, errors }) => (
                <>
                    <input type="hidden" name="semester" value={term.value} />

                    <ul className="divide-y rounded-xl border">
                        {sheet.students.map((student, index) => (
                            <li
                                key={student.enrollment_id}
                                className="space-y-3 p-4"
                            >
                                <p className="text-sm font-medium break-words">
                                    {index + 1}. {student.name}
                                    {student.left && (
                                        <span className="font-normal text-muted-foreground">
                                            {' '}
                                            (sudah keluar dari kelas)
                                        </span>
                                    )}
                                </p>

                                {extracurriculars.length > 0 && (
                                    <fieldset className="space-y-2">
                                        <legend className="mb-1 text-xs font-medium text-muted-foreground uppercase">
                                            Ekstrakurikuler
                                        </legend>
                                        <div className="grid grid-cols-[repeat(auto-fill,minmax(11rem,1fr))] gap-2">
                                            {extracurriculars.map((item) => (
                                                <div
                                                    key={item.id}
                                                    className="grid gap-1"
                                                >
                                                    <Label
                                                        htmlFor={`ri-${student.enrollment_id}-${item.id}`}
                                                        className="text-xs"
                                                    >
                                                        {item.name}
                                                    </Label>
                                                    {letterSelect(
                                                        student.enrollment_id,
                                                        student.name,
                                                        item,
                                                        'Tidak ikut',
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    </fieldset>
                                )}

                                {personalities.length > 0 && (
                                    <fieldset className="space-y-2">
                                        <legend className="mb-1 text-xs font-medium text-muted-foreground uppercase">
                                            Kepribadian
                                        </legend>
                                        {personalities.map((item) => {
                                            const value =
                                                values[student.enrollment_id][
                                                    item.id
                                                ];
                                            const auto = value.letter
                                                ? (item.descriptions[
                                                      value.letter
                                                  ] ?? '')
                                                : '';

                                            return (
                                                <div
                                                    key={item.id}
                                                    className="grid gap-1 sm:grid-cols-[9rem_11rem_minmax(0,1fr)] sm:items-center sm:gap-2"
                                                >
                                                    <Label
                                                        htmlFor={`ri-${student.enrollment_id}-${item.id}`}
                                                        className="text-sm"
                                                    >
                                                        {item.name}
                                                    </Label>
                                                    {letterSelect(
                                                        student.enrollment_id,
                                                        student.name,
                                                        item,
                                                        'Belum dinilai',
                                                    )}
                                                    <Input
                                                        aria-label={`Keterangan ${item.name} untuk ${student.name}`}
                                                        name={`grades[${student.enrollment_id}][${item.id}][note]`}
                                                        value={value.note}
                                                        maxLength={noteMax}
                                                        disabled={!canEdit}
                                                        placeholder={
                                                            auto
                                                                ? `Otomatis: ${auto}`
                                                                : 'Keterangan (opsional)'
                                                        }
                                                        onChange={(event) =>
                                                            set(
                                                                student.enrollment_id,
                                                                item.id,
                                                                {
                                                                    note: event
                                                                        .target
                                                                        .value,
                                                                },
                                                            )
                                                        }
                                                    />
                                                </div>
                                            );
                                        })}
                                    </fieldset>
                                )}
                            </li>
                        ))}
                    </ul>

                    <p className="text-xs text-muted-foreground">
                        Keterangan kepribadian otomatis mengikuti huruf yang
                        dipilih; isi kolom keterangan hanya bila ingin
                        menggantinya untuk siswa itu.
                    </p>

                    <InputError
                        message={
                            errors.grades ??
                            errors.form ??
                            errors.semester ??
                            Object.entries(errors).find(([field]) =>
                                field.startsWith('grades.'),
                            )?.[1]
                        }
                    />

                    {canEdit && (
                        <div className="sticky bottom-0 border-t bg-card py-3">
                            <Button
                                type="submit"
                                disabled={processing}
                                className="w-full sm:w-auto"
                            >
                                <Save />
                                {processing ? 'Menyimpan…' : 'Simpan nilai'}
                            </Button>
                        </div>
                    )}
                </>
            )}
        </Form>
    );
}
