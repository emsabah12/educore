import { Form, Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    CircleAlert,
    Lock,
    LockOpen,
    RefreshCw,
    Save,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { GradingRules as Rules } from '@/components/academic/grading';
import {
    allComponents,
    aspectScore,
    finalScore,
    formatScore,
    parseScore,
    describePredicate,
    predicate,
    scaleText,
    showsAspects,
} from '@/components/academic/grading';
import { selectClass } from '@/components/academic/student-actions';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type Student = {
    enrollment_id: string;
    student_id: string;
    name: string;
    gender: string | null;
    nisn: string | null;
    student_number: string | null;
    left: boolean;
    scores: Record<string, number | null>;
    aspects: Record<string, number | null>;
    letters: Record<string, string | null>;
    final: number | null;
    predicate: string | null;
    description: string | null;
};

type Props = {
    class: { id: string; name: string; organization: string; year: string };
    subject: { id: string; name: string; teacher: string | null };
    terms: Option[];
    term: { value: string; label: string; start: string; end: string } | null;
    sheet: {
        status: 'BELUM' | 'DRAFT' | 'FINAL';
        rules: Rules;
        grade_type: 'NUMBER' | 'LETTER';
        outdated: boolean;
        finalized: { by: string | null; at: string } | null;
        students: Student[];
        complete: number;
        required: number;
    } | null;
    past: boolean;
    canEdit: boolean;
    canManage: boolean;
    limits: { description: number; reason: number };
    baseUrl: string;
    backUrl: string;
    backLabel: string;
};

type Draft = {
    scores: Record<string, string>;
    letters: Record<string, string>;
    description: string;
};

const stampFormat = new Intl.DateTimeFormat('id-ID', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

function isInvalid(value: string): boolean {
    return value.trim() !== '' && parseScore(value) === null;
}

function showScore(value: number | null): string {
    return formatScore(value);
}

/** Ringkasan bobot: "UTS 50%, UAS 50%" per aspek. */
function weights(rules: Rules): string {
    return rules.aspects
        .map((aspect) => {
            const parts = aspect.components
                .map((component) => `${component.name} ${component.weight}%`)
                .join(', ');

            return showsAspects(rules) ? `${aspect.name}: ${parts}` : parts;
        })
        .join(' · ');
}

/**
 * Lembar nilai satu mapel kelas per semester (PRD-002 §19 AC-5b). Lembaga beraspek (§22 AC-8a):
 * komponen dikelompokkan per aspek, nilai akhir = rata-rata aspek. Nilai aspek, nilai akhir &
 * predikat dihitung langsung saat diketik; server tetap menghitung ulang dan memeriksa semua aturan.
 * Mapel bernilai huruf (§22.4 AC-8b): per siswa memilih huruf skala predikat untuk tiap aspek.
 */
export default function GradeSheet(props: Props) {
    const { class: item, subject, terms, term, sheet, baseUrl } = props;

    return (
        <>
            <Head title={`Nilai ${subject.name} ${item.name}`} />

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
                    title={`Nilai ${subject.name} · ${item.name}`}
                    description={`${item.organization} · Tahun ajaran ${item.year}${subject.teacher ? ` · Pengampu: ${subject.teacher}` : ''}`}
                />

                {term === null || sheet === null ? (
                    <Notice>
                        Tahun ajaran kelas ini belum punya semester. Atur
                        semesternya di Kalender akademik.
                    </Notice>
                ) : (
                    <>
                        <div className="grid grid-cols-[repeat(auto-fit,minmax(14rem,1fr))] items-end gap-3 rounded-xl border p-4">
                            <div className="grid gap-1.5">
                                <Label htmlFor="grade-term">Semester</Label>
                                <select
                                    id="grade-term"
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
                            <div className="space-y-1 text-sm">
                                <p className="flex flex-wrap items-center gap-1.5">
                                    <StatusBadge status={sheet.status} />
                                    <span className="text-muted-foreground">
                                        {sheet.complete} dari {sheet.required}{' '}
                                        siswa sudah{' '}
                                        {sheet.grade_type === 'LETTER'
                                            ? 'dinilai lengkap'
                                            : 'punya nilai akhir'}
                                    </span>
                                </p>
                                {sheet.grade_type === 'LETTER' ? (
                                    <p className="text-muted-foreground">
                                        Nilai huruf: pilih satu huruf untuk tiap
                                        siswa ·{' '}
                                        {sheet.rules.predicates
                                            .map((level) =>
                                                describePredicate(
                                                    sheet.rules,
                                                    level.letter,
                                                ),
                                            )
                                            .join(', ')}
                                    </p>
                                ) : (
                                    <p className="text-muted-foreground">
                                        KKM {sheet.rules.kkm} ·{' '}
                                        {scaleText(sheet.rules)} ·{' '}
                                        {weights(sheet.rules)}
                                    </p>
                                )}
                                {sheet.grade_type === 'NUMBER' &&
                                    sheet.rules.aspects.length > 1 && (
                                        <p className="text-muted-foreground">
                                            Nilai akhir = rata-rata nilai{' '}
                                            {sheet.rules.aspects
                                                .map((aspect) => aspect.name)
                                                .join(' dan ')}
                                            .
                                        </p>
                                    )}
                            </div>
                        </div>

                        <Banners {...props} sheet={sheet} term={term} />

                        {sheet.students.length === 0 ? (
                            <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                                Belum ada siswa di kelas ini pada semester
                                tersebut.
                            </p>
                        ) : props.canEdit && sheet.status !== 'FINAL' ? (
                            <SheetForm
                                // Data tersimpan berubah (mis. sesudah Simpan) → form dimuat ulang dari server.
                                key={`${term.value}-${sheet.status}-${JSON.stringify(
                                    sheet.students.map((student) => [
                                        student.scores,
                                        student.letters,
                                        student.description,
                                    ]),
                                )}`}
                                {...props}
                                sheet={sheet}
                                term={term}
                            />
                        ) : (
                            <ReadOnly sheet={sheet} />
                        )}
                    </>
                )}
            </div>
        </>
    );
}

function StatusBadge({ status }: { status: 'BELUM' | 'DRAFT' | 'FINAL' }) {
    if (status === 'FINAL') {
        return (
            <Badge>
                <Lock />
                Final
            </Badge>
        );
    }

    return (
        <Badge variant="outline">
            {status === 'DRAFT' ? 'Draf' : 'Belum diisi'}
        </Badge>
    );
}

/** Pemberitahuan: tahun lewat, final (+ buka kembali), aturan berubah (+ terapkan aturan terbaru). */
function Banners({
    sheet,
    term,
    past,
    canManage,
    limits,
    baseUrl,
}: Props & {
    sheet: NonNullable<Props['sheet']>;
    term: NonNullable<Props['term']>;
}) {
    const [reopening, setReopening] = useState(false);

    return (
        <>
            {past && (
                <Notice>
                    Tahun ajaran kelas ini sudah lewat; nilainya hanya bisa
                    dilihat.
                </Notice>
            )}

            {sheet.status === 'FINAL' && (
                <div className="space-y-3 rounded-xl border p-4 text-sm">
                    <p className="flex items-start gap-2">
                        <Lock className="mt-0.5 size-4 shrink-0" />
                        <span>
                            Nilai sudah difinalkan
                            {sheet.finalized?.by
                                ? ` oleh ${sheet.finalized.by}`
                                : ''}
                            {sheet.finalized
                                ? ` (${stampFormat.format(new Date(sheet.finalized.at))})`
                                : ''}
                            . Nilai ini dipakai di rapor dan hanya bisa diubah
                            setelah dibuka kembali oleh operator.
                        </span>
                    </p>
                    {canManage &&
                        (reopening ? (
                            <Form
                                action={`${baseUrl}/buka-kembali`}
                                method="post"
                                errorBag="reopenGrades"
                                options={{ preserveScroll: true }}
                                onSuccess={() => setReopening(false)}
                                className="space-y-2"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="semester"
                                            value={term.value}
                                        />
                                        <Label htmlFor="grade-reopen-reason">
                                            Alasan membuka kembali
                                        </Label>
                                        <textarea
                                            id="grade-reopen-reason"
                                            name="reason"
                                            rows={2}
                                            maxLength={limits.reason}
                                            required
                                            className="w-full rounded-md border border-input bg-transparent px-3.5 py-2.5 text-base transition-colors outline-none placeholder:text-muted-foreground hover:border-foreground/60 focus-visible:border-primary focus-visible:ring-1 focus-visible:ring-primary disabled:cursor-not-allowed disabled:bg-muted disabled:opacity-60 md:text-sm"
                                        />
                                        <InputError
                                            message={
                                                errors.reason ?? errors.form
                                            }
                                        />
                                        <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                Buka kembali
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() =>
                                                    setReopening(false)
                                                }
                                            >
                                                Batal
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        ) : (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setReopening(true)}
                            >
                                <LockOpen />
                                Buka kembali nilai
                            </Button>
                        ))}
                </div>
            )}

            {sheet.outdated && (
                <div className="space-y-2 rounded-md border border-amber-500/50 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                    <p className="flex gap-2">
                        <CircleAlert className="mt-0.5 size-4 shrink-0" />
                        <span>
                            Aturan penilaian lembaga sudah berubah sejak nilai
                            ini pertama kali disimpan. Lembar ini tetap memakai
                            aturan lama
                            {canManage
                                ? ' sampai Anda menerapkan aturan terbaru (nilai komponen yang kodenya sama tetap tersimpan; bila jenis nilai angka/huruf berganti, nilai lama dikosongkan).'
                                : '; minta operator menerapkan aturan terbaru bila perlu.'}
                        </span>
                    </p>
                    {canManage && (
                        <Form
                            action={`${baseUrl}/aturan-terbaru`}
                            method="post"
                            errorBag="applyGradingRules"
                            options={{ preserveScroll: true }}
                        >
                            {({ processing, errors }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="semester"
                                        value={term.value}
                                    />
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        <RefreshCw />
                                        Terapkan aturan terbaru
                                    </Button>
                                    <InputError message={errors.form} />
                                </>
                            )}
                        </Form>
                    )}
                </div>
            )}
        </>
    );
}

function SheetForm({
    sheet,
    term,
    limits,
    baseUrl,
}: Props & {
    sheet: NonNullable<Props['sheet']>;
    term: NonNullable<Props['term']>;
}) {
    const { rules, students } = sheet;
    const letterMode = sheet.grade_type === 'LETTER';
    const initial = useMemo(
        () =>
            Object.fromEntries(
                students.map((student) => [
                    student.enrollment_id,
                    {
                        scores: Object.fromEntries(
                            allComponents(rules).map((component) => [
                                component.code,
                                showScore(
                                    student.scores[component.code] ?? null,
                                ).replace('.', ','),
                            ]),
                        ),
                        letters: Object.fromEntries(
                            rules.aspects.map((aspect) => [
                                aspect.code,
                                student.letters[aspect.code] ?? '',
                            ]),
                        ),
                        description: student.description ?? '',
                    },
                ]),
            ) as Record<string, Draft>,
        [students, rules],
    );
    const [values, setValues] = useState<Record<string, Draft>>(initial);
    const [confirming, setConfirming] = useState(false);
    const dirty = JSON.stringify(values) !== JSON.stringify(initial);

    const setScore = (id: string, code: string, value: string) =>
        setValues((current) => ({
            ...current,
            [id]: {
                ...current[id],
                scores: { ...current[id].scores, [code]: value },
            },
        }));

    const setLetter = (id: string, code: string, value: string) =>
        setValues((current) => ({
            ...current,
            [id]: {
                ...current[id],
                letters: { ...current[id].letters, [code]: value },
            },
        }));

    const setDescription = (id: string, value: string) =>
        setValues((current) => ({
            ...current,
            [id]: { ...current[id], description: value },
        }));

    const invalid =
        !letterMode &&
        students.some((student) =>
            allComponents(rules).some((component) =>
                isInvalid(values[student.enrollment_id].scores[component.code]),
            ),
        );

    return (
        <div className="space-y-4">
            <Form
                action={baseUrl}
                method="post"
                errorBag="grades"
                options={{ preserveScroll: true }}
                className="space-y-4"
            >
                {({ processing, errors }) => (
                    <>
                        <input
                            type="hidden"
                            name="semester"
                            value={term.value}
                        />

                        <ul className="divide-y rounded-xl border">
                            {students.map((student, index) => {
                                const draft = values[student.enrollment_id];
                                const final = finalScore(rules, draft.scores);

                                return (
                                    <li
                                        key={student.enrollment_id}
                                        className="space-y-2 p-4"
                                    >
                                        <div className="flex flex-wrap items-start justify-between gap-2">
                                            <div className="min-w-0 text-sm">
                                                <p className="font-medium break-words">
                                                    {index + 1}. {student.name}
                                                </p>
                                                <p className="text-muted-foreground">
                                                    {[
                                                        student.gender,
                                                        student.nisn
                                                            ? `NISN ${student.nisn}`
                                                            : null,
                                                        student.left
                                                            ? 'sudah keluar dari kelas'
                                                            : null,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </p>
                                            </div>
                                            {letterMode ? (
                                                <LetterBadge
                                                    rules={rules}
                                                    letters={draft.letters}
                                                />
                                            ) : (
                                                <FinalBadge
                                                    rules={rules}
                                                    score={final}
                                                />
                                            )}
                                        </div>

                                        {letterMode && (
                                            <div className="grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-2">
                                                {rules.aspects.map((aspect) => {
                                                    const id = `l-${student.enrollment_id}-${aspect.code}`;

                                                    return (
                                                        <div
                                                            key={aspect.code}
                                                            className="grid gap-1"
                                                        >
                                                            <Label
                                                                htmlFor={id}
                                                                className="text-xs"
                                                            >
                                                                {showsAspects(
                                                                    rules,
                                                                )
                                                                    ? aspect.name
                                                                    : 'Nilai huruf'}
                                                            </Label>
                                                            <select
                                                                id={id}
                                                                name={`grades[${student.enrollment_id}][letters][${aspect.code}]`}
                                                                className={
                                                                    selectClass
                                                                }
                                                                value={
                                                                    draft
                                                                        .letters[
                                                                        aspect
                                                                            .code
                                                                    ]
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    setLetter(
                                                                        student.enrollment_id,
                                                                        aspect.code,
                                                                        event
                                                                            .target
                                                                            .value,
                                                                    )
                                                                }
                                                            >
                                                                <option value="">
                                                                    Belum
                                                                    dinilai
                                                                </option>
                                                                {rules.predicates.map(
                                                                    (level) => (
                                                                        <option
                                                                            key={
                                                                                level.letter
                                                                            }
                                                                            value={
                                                                                level.letter
                                                                            }
                                                                        >
                                                                            {describePredicate(
                                                                                rules,
                                                                                level.letter,
                                                                            )}
                                                                        </option>
                                                                    ),
                                                                )}
                                                            </select>
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        )}

                                        {!letterMode &&
                                            rules.aspects.map((aspect) => (
                                                <AspectGroup
                                                    key={aspect.code}
                                                    rules={rules}
                                                    aspect={aspect}
                                                    score={aspectScore(
                                                        aspect,
                                                        draft.scores,
                                                    )}
                                                >
                                                    {aspect.components.map(
                                                        (component) => {
                                                            const id = `g-${student.enrollment_id}-${component.code}`;
                                                            const value =
                                                                draft.scores[
                                                                    component
                                                                        .code
                                                                ];

                                                            return (
                                                                <div
                                                                    key={
                                                                        component.code
                                                                    }
                                                                    className="grid gap-1"
                                                                >
                                                                    <Label
                                                                        htmlFor={
                                                                            id
                                                                        }
                                                                        className="text-xs"
                                                                        title={
                                                                            component.name
                                                                        }
                                                                    >
                                                                        {
                                                                            component.code
                                                                        }{' '}
                                                                        <span className="text-muted-foreground">
                                                                            {
                                                                                component.weight
                                                                            }
                                                                            %
                                                                        </span>
                                                                    </Label>
                                                                    <Input
                                                                        id={id}
                                                                        name={`grades[${student.enrollment_id}][scores][${component.code}]`}
                                                                        inputMode="decimal"
                                                                        autoComplete="off"
                                                                        value={
                                                                            value
                                                                        }
                                                                        aria-invalid={isInvalid(
                                                                            value,
                                                                        )}
                                                                        onChange={(
                                                                            event,
                                                                        ) =>
                                                                            setScore(
                                                                                student.enrollment_id,
                                                                                component.code,
                                                                                event
                                                                                    .target
                                                                                    .value,
                                                                            )
                                                                        }
                                                                    />
                                                                </div>
                                                            );
                                                        },
                                                    )}
                                                </AspectGroup>
                                            ))}

                                        <Input
                                            aria-label={`Deskripsi ${student.name}`}
                                            name={`grades[${student.enrollment_id}][description]`}
                                            placeholder="Deskripsi capaian (opsional)"
                                            maxLength={limits.description}
                                            value={draft.description}
                                            onChange={(event) =>
                                                setDescription(
                                                    student.enrollment_id,
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </li>
                                );
                            })}
                        </ul>

                        {invalid && (
                            <p className="text-sm text-red-600 dark:text-red-400">
                                Ada nilai yang tidak sah (harus angka 0–100,
                                paling banyak 2 angka di belakang koma).
                            </p>
                        )}
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

                        <div className="sticky bottom-0 grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2 border-t bg-card py-3 sm:flex">
                            <Button
                                type="submit"
                                disabled={processing || invalid}
                            >
                                <Save />
                                {processing ? 'Menyimpan…' : 'Simpan nilai'}
                            </Button>
                            {sheet.status === 'DRAFT' && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setConfirming(true)}
                                    disabled={dirty}
                                    title={
                                        dirty
                                            ? 'Simpan perubahan dulu sebelum memfinalkan.'
                                            : undefined
                                    }
                                >
                                    <Lock />
                                    Finalkan
                                </Button>
                            )}
                        </div>
                        {dirty && sheet.status === 'DRAFT' && (
                            <p className="-mt-2 text-xs text-muted-foreground">
                                Ada perubahan yang belum disimpan. Simpan dulu
                                sebelum memfinalkan.
                            </p>
                        )}
                    </>
                )}
            </Form>

            {confirming && (
                <Form
                    action={`${baseUrl}/final`}
                    method="post"
                    errorBag="finalizeGrades"
                    options={{ preserveScroll: true }}
                    onSuccess={() => setConfirming(false)}
                    className="space-y-3 rounded-xl border border-border bg-muted/20 p-4 text-sm"
                >
                    {({ processing, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="semester"
                                value={term.value}
                            />
                            <p>
                                Finalkan nilai {term.label}? Semua siswa yang
                                masih di kelas harus sudah dinilai lengkap.
                                Sesudahnya nilai hanya bisa diubah setelah
                                dibuka kembali oleh operator.
                            </p>
                            <InputError message={errors.form} />
                            <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
                                <Button type="submit" disabled={processing}>
                                    {processing
                                        ? 'Memfinalkan…'
                                        : 'Ya, finalkan'}
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setConfirming(false)}
                                >
                                    Batal
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            )}
        </div>
    );
}

function ReadOnly({ sheet }: { sheet: NonNullable<Props['sheet']> }) {
    const { rules } = sheet;

    return (
        <ul className="divide-y rounded-xl border">
            {sheet.students.map((student, index) => (
                <li key={student.enrollment_id} className="space-y-2 p-4">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <div className="min-w-0 text-sm">
                            <p className="font-medium break-words">
                                {index + 1}. {student.name}
                            </p>
                            {student.left && (
                                <p className="text-muted-foreground">
                                    sudah keluar dari kelas
                                </p>
                            )}
                        </div>
                        {sheet.grade_type === 'LETTER' ? (
                            <LetterBadge
                                rules={rules}
                                letters={student.letters}
                            />
                        ) : (
                            <FinalBadge rules={rules} score={student.final} />
                        )}
                    </div>
                    {sheet.grade_type === 'NUMBER' &&
                        rules.aspects.map((aspect) => (
                            <AspectGroup
                                key={aspect.code}
                                rules={rules}
                                aspect={aspect}
                                score={student.aspects[aspect.code] ?? null}
                            >
                                {aspect.components.map((component) => (
                                    <div
                                        key={component.code}
                                        className="rounded-md bg-muted/50 px-2 py-1 text-sm"
                                    >
                                        <p className="text-xs text-muted-foreground">
                                            {component.code} {component.weight}%
                                        </p>
                                        <p className="tabular-nums">
                                            {showScore(
                                                student.scores[
                                                    component.code
                                                ] ?? null,
                                            ) || '—'}
                                        </p>
                                    </div>
                                ))}
                            </AspectGroup>
                        ))}
                    {student.description && (
                        <p className="text-sm break-words text-muted-foreground">
                            {student.description}
                        </p>
                    )}
                </li>
            ))}
        </ul>
    );
}

/**
 * Isian komponen satu aspek. Aturan beraspek → diberi judul nama aspek & nilai aspeknya; aturan
 * satu aspek bawaan → kotak komponen saja seperti sebelumnya.
 */
function AspectGroup({
    rules,
    aspect,
    score,
    children,
}: {
    rules: Rules;
    aspect: Rules['aspects'][number];
    score: number | null;
    children: React.ReactNode;
}) {
    const grid = (
        <div className="grid grid-cols-[repeat(auto-fit,minmax(6.5rem,1fr))] gap-2">
            {children}
        </div>
    );

    if (!showsAspects(rules)) {
        return grid;
    }

    return (
        <fieldset className="space-y-2 rounded-lg bg-muted/40 p-3">
            <legend className="sr-only">{aspect.name}</legend>
            <p className="flex flex-wrap items-center justify-between gap-2 text-sm">
                <span className="font-medium">{aspect.name}</span>
                <span className="text-muted-foreground tabular-nums">
                    {score === null
                        ? 'Nilai: —'
                        : `Nilai: ${score} · ${describePredicate(rules, predicate(rules, score))}`}
                </span>
            </p>
            {grid}
        </fieldset>
    );
}

function FinalBadge({ rules, score }: { rules: Rules; score: number | null }) {
    if (score === null) {
        return (
            <span className="text-sm text-muted-foreground">
                Nilai akhir: —
            </span>
        );
    }

    const below = score < rules.kkm;

    return (
        <span
            className={
                'rounded-md px-2 py-1 text-sm font-medium tabular-nums ' +
                (below
                    ? 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300'
                    : 'bg-muted')
            }
        >
            Akhir {formatScore(score, rules.aspects.length > 1 ? 1 : 0)} ·{' '}
            {describePredicate(rules, predicate(rules, score))}
            {below && ' · di bawah KKM'}
        </span>
    );
}

/** Ringkasan nilai huruf: "B Baik" atau per aspek "Praktik: B". */
function LetterBadge({
    rules,
    letters,
}: {
    rules: Rules;
    letters: Record<string, string | null>;
}) {
    const filled = rules.aspects.filter((aspect) => letters[aspect.code]);

    if (filled.length < rules.aspects.length) {
        return (
            <span className="text-sm text-muted-foreground">Belum lengkap</span>
        );
    }

    return (
        <span className="rounded-md bg-muted px-2 py-1 text-sm font-medium">
            {rules.aspects
                .map((aspect) => {
                    const text = describePredicate(
                        rules,
                        letters[aspect.code] ?? '',
                    );

                    return rules.aspects.length > 1
                        ? `${aspect.name}: ${text}`
                        : text;
                })
                .join(' · ')}
        </span>
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
