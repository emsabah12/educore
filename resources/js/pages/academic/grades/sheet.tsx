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
import { selectClass } from '@/components/academic/student-actions';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type Component = { code: string; name: string; weight: number };

type Rules = {
    kkm: number;
    grade_a: number;
    grade_b: number;
    components: Component[];
};

type Student = {
    enrollment_id: string;
    student_id: string;
    name: string;
    gender: string | null;
    nisn: string | null;
    student_number: string | null;
    left: boolean;
    scores: Record<string, number | null>;
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

type Draft = { scores: Record<string, string>; description: string };

const SCORE_PATTERN = /^\d{1,3}([.,]\d{1,2})?$/;

const stampFormat = new Intl.DateTimeFormat('id-ID', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

/** "82,5" atau "82.5" → 82.5; kosong/tidak sah → null. */
function parseScore(value: string): number | null {
    const text = value.trim();

    if (text === '' || !SCORE_PATTERN.test(text)) {
        return null;
    }

    const score = Number(text.replace(',', '.'));

    return score <= 100 ? score : null;
}

function isInvalid(value: string): boolean {
    return value.trim() !== '' && parseScore(value) === null;
}

/** Sama dengan server: Σ nilai × bobot ÷ 100 dalam perseratus, dibulatkan setengah ke atas. */
function finalScore(
    rules: Rules,
    scores: Record<string, string>,
): number | null {
    let sum = 0;

    for (const component of rules.components) {
        const score = parseScore(scores[component.code] ?? '');

        if (score === null) {
            return null;
        }

        sum += Math.round(score * 100) * component.weight;
    }

    return Math.floor((sum + 5000) / 10000);
}

function predicate(rules: Rules, score: number): string {
    if (score >= rules.grade_a) {
        return 'A';
    }

    if (score >= rules.grade_b) {
        return 'B';
    }

    return score >= rules.kkm ? 'C' : 'D';
}

function showScore(value: number | null): string {
    return value === null ? '' : value.toLocaleString('id-ID');
}

/**
 * Lembar nilai satu mapel kelas per semester (PRD-002 §19 AC-5b). Nilai akhir & predikat dihitung
 * langsung saat diketik; server tetap menghitung ulang dan memeriksa semua aturan.
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
                                        siswa sudah punya nilai akhir
                                    </span>
                                </p>
                                <p className="text-muted-foreground">
                                    KKM {sheet.rules.kkm} · A ≥{' '}
                                    {sheet.rules.grade_a}, B ≥{' '}
                                    {sheet.rules.grade_b}, C ≥ {sheet.rules.kkm}
                                    , D di bawahnya ·{' '}
                                    {sheet.rules.components
                                        .map(
                                            (component) =>
                                                `${component.name} ${component.weight}%`,
                                        )
                                        .join(', ')}
                                </p>
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
                                            className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
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
                                ? ' sampai Anda menerapkan aturan terbaru (nilai komponen yang kodenya sama tetap tersimpan).'
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
    const initial = useMemo(
        () =>
            Object.fromEntries(
                students.map((student) => [
                    student.enrollment_id,
                    {
                        scores: Object.fromEntries(
                            rules.components.map((component) => [
                                component.code,
                                showScore(
                                    student.scores[component.code] ?? null,
                                ).replace('.', ','),
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

    const setDescription = (id: string, value: string) =>
        setValues((current) => ({
            ...current,
            [id]: { ...current[id], description: value },
        }));

    const invalid = students.some((student) =>
        rules.components.some((component) =>
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
                                            <FinalBadge
                                                rules={rules}
                                                score={final}
                                            />
                                        </div>

                                        <div className="grid grid-cols-[repeat(auto-fit,minmax(6.5rem,1fr))] gap-2">
                                            {rules.components.map(
                                                (component) => {
                                                    const id = `g-${student.enrollment_id}-${component.code}`;
                                                    const value =
                                                        draft.scores[
                                                            component.code
                                                        ];

                                                    return (
                                                        <div
                                                            key={component.code}
                                                            className="grid gap-1"
                                                        >
                                                            <Label
                                                                htmlFor={id}
                                                                className="text-xs"
                                                                title={
                                                                    component.name
                                                                }
                                                            >
                                                                {component.code}{' '}
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
                                                                value={value}
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
                                        </div>

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

                        <div className="sticky bottom-0 grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2 border-t bg-background py-3 sm:flex">
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
                    className="space-y-3 rounded-xl border border-primary/30 bg-muted/20 p-4 text-sm"
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
                                masih di kelas harus sudah punya nilai akhir.
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
                        <FinalBadge rules={rules} score={student.final} />
                    </div>
                    <dl className="grid grid-cols-[repeat(auto-fit,minmax(6.5rem,1fr))] gap-2 text-sm">
                        {rules.components.map((component) => (
                            <div
                                key={component.code}
                                className="rounded-md bg-muted/50 px-2 py-1"
                            >
                                <dt className="text-xs text-muted-foreground">
                                    {component.code} {component.weight}%
                                </dt>
                                <dd className="tabular-nums">
                                    {showScore(
                                        student.scores[component.code] ?? null,
                                    ) || '—'}
                                </dd>
                            </div>
                        ))}
                    </dl>
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
            Akhir {score} · {predicate(rules, score)}
            {below && ' · di bawah KKM'}
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
