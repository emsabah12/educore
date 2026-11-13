import { Form, Head, router } from '@inertiajs/react';
import {
    CalendarRange,
    ClipboardCheck,
    Pencil,
    Plus,
    Trash2,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmAction } from '@/components/confirm-action';
import { FormDialog } from '@/components/form-dialog';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { selectClass } from '@/components/native-select';
import { PageTabs, usePageTab } from '@/components/page-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type Period = {
    id: string;
    name: string;
    start_date: string;
    end_date: string;
    locked: boolean;
    started: boolean;
    assessed: number;
};

type Component = { name: string; weight: number };

type Assessment = {
    group: string;
    scores: (Component & { score: number })[];
    final_score: number;
    predicate: string;
    predicate_label: string;
    strengths: string | null;
    suggestions: string | null;
    assessed_by: string | null;
    assessed_on: string;
};

type Row = {
    employee_id: string;
    name: string;
    employee_number: string | null;
    positions: string[];
    suggested_group: string;
    current: boolean;
    is_self: boolean;
    assessment: Assessment | null;
};

type Props = {
    periods: Period[];
    organizations: Option[];
    filters: { periode: string | null; lembaga: string | null };
    rows: Row[];
    components: Record<string, Component[]>;
    groups: { value: string; slug: string; label: string }[];
    predicates: { value: string; label: string; range: string }[];
    canManage: boolean;
    limits: {
        note: number;
        periodName: number;
        componentName: number;
        components: number;
    };
};

const BASE_URL = '/pegawai/kinerja';
const TABS = ['penilaian', 'periode', 'komponen'] as const;

const textareaClass =
    'min-h-20 w-full rounded-md border border-input bg-transparent px-3.5 py-2.5 text-base outline-none transition-colors placeholder:text-muted-foreground hover:border-foreground/60 focus-visible:border-primary focus-visible:ring-1 focus-visible:ring-primary md:text-sm';

const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' });
const scoreFormat = new Intl.NumberFormat('id-ID', {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
});

function formatDate(value: string): string {
    const [year, month, day] = value.slice(0, 10).split('-').map(Number);

    return dateFormat.format(new Date(year, month - 1, day));
}

/** Predikat sama dengan server (OD-HR-18): batas bawah tiap rentang, tanpa pembulatan. */
function predicateOf(
    score: number,
    predicates: Props['predicates'],
): Props['predicates'][number] {
    const index =
        score >= 91
            ? 0
            : score >= 76
              ? 1
              : score >= 61
                ? 2
                : score >= 51
                  ? 3
                  : 4;

    return predicates[index];
}

function PredicateBadge({ assessment }: { assessment: Assessment }) {
    const strong =
        assessment.predicate === 'AMAT_BAIK' || assessment.predicate === 'BAIK';

    return (
        <Badge variant={strong ? 'default' : 'secondary'}>
            {scoreFormat.format(assessment.final_score)} ·{' '}
            {assessment.predicate_label}
        </Badge>
    );
}

/**
 * Penilaian kinerja ringkas (PRD-001 §19.2, HR-8b). Penilai (Kepala Lembaga untuk lembaganya, Admin SDM semua)
 * mengisi nilai 0–100 per komponen; Admin SDM / Admin Yayasan mengatur periode dan komponen di tab tersendiri.
 */
export default function PerformanceIndex(props: Props) {
    const { canManage } = props;
    const [tab, setTab] = usePageTab(TABS, 'penilaian');
    const current = canManage ? tab : 'penilaian';

    return (
        <>
            <Head title="Penilaian kinerja" />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Penilaian kinerja"
                    description="Nilai 0–100 per komponen, nilai akhir rata-rata berbobot dengan predikat ala PKG. Kepala Lembaga menilai pegawai lembaganya; Admin SDM menilai semua."
                />

                {canManage && (
                    <PageTabs
                        label="Bagian penilaian kinerja"
                        tabs={[
                            { value: 'penilaian', label: 'Penilaian' },
                            {
                                value: 'periode',
                                label: 'Periode',
                                badge: props.periods.length,
                            },
                            { value: 'komponen', label: 'Komponen & bobot' },
                        ]}
                        value={current}
                        onChange={setTab}
                    />
                )}

                {current === 'penilaian' && <AssessmentTab {...props} />}
                {current === 'periode' && <PeriodsTab {...props} />}
                {current === 'komponen' && <ComponentsTab {...props} />}
            </div>
        </>
    );
}

function AssessmentTab({
    periods,
    organizations,
    filters,
    rows,
    components,
    groups,
    predicates,
    canManage,
    limits,
}: Props) {
    const period = periods.find((item) => item.id === filters.periode) ?? null;
    const assessed = rows.filter((row) => row.assessment !== null).length;
    const editable = period !== null && period.started && !period.locked;

    const apply = (next: Partial<Props['filters']>) => {
        const merged = { ...filters, ...next };

        router.get(
            BASE_URL,
            {
                periode: merged.periode ?? undefined,
                lembaga: merged.lembaga ?? undefined,
            },
            { preserveScroll: true, replace: true },
        );
    };

    if (periods.length === 0) {
        return (
            <div className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                Belum ada periode penilaian.{' '}
                {canManage
                    ? 'Buat periode dulu di tab Periode.'
                    : 'Minta Admin SDM membuat periode penilaian.'}
            </div>
        );
    }

    if (organizations.length === 0) {
        return (
            <div className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                Tidak ada lembaga aktif yang bisa Anda nilai.
            </div>
        );
    }

    return (
        <div className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="grid gap-1.5">
                    <Label htmlFor="filter-period">Periode</Label>
                    <select
                        id="filter-period"
                        className={selectClass}
                        value={filters.periode ?? ''}
                        onChange={(event) =>
                            apply({ periode: event.target.value })
                        }
                    >
                        {periods.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.name}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="filter-organization">Lembaga</Label>
                    <select
                        id="filter-organization"
                        className={selectClass}
                        value={filters.lembaga ?? ''}
                        onChange={(event) =>
                            apply({ lembaga: event.target.value })
                        }
                    >
                        {organizations.map((item) => (
                            <option key={item.value} value={item.value}>
                                {item.label}
                            </option>
                        ))}
                    </select>
                </div>
            </div>

            {period && (
                <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                    <CalendarRange className="size-4" />
                    <span>
                        {formatDate(period.start_date)} –{' '}
                        {formatDate(period.end_date)}
                    </span>
                    {period.locked ? (
                        <Badge variant="secondary">Dikunci</Badge>
                    ) : !period.started ? (
                        <Badge variant="outline">Belum dimulai</Badge>
                    ) : null}
                    <span>
                        · {assessed} dari {rows.length} pegawai sudah dinilai
                    </span>
                </div>
            )}

            {rows.length === 0 ? (
                <div className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                    Belum ada pegawai aktif dengan penempatan utama di lembaga
                    ini.
                </div>
            ) : (
                <ul className="divide-y rounded-xl border">
                    {rows.map((row) => (
                        <AssessmentRow
                            key={row.employee_id}
                            row={row}
                            periodId={filters.periode ?? ''}
                            editable={editable}
                            components={components}
                            groups={groups}
                            predicates={predicates}
                            noteMax={limits.note}
                        />
                    ))}
                </ul>
            )}

            <p className="text-xs text-muted-foreground">
                Predikat:{' '}
                {predicates
                    .map((item) => `${item.label} ${item.range}`)
                    .join(' · ')}
            </p>
        </div>
    );
}

function AssessmentRow({
    row,
    periodId,
    editable,
    components,
    groups,
    predicates,
    noteMax,
}: {
    row: Row;
    periodId: string;
    editable: boolean;
    components: Props['components'];
    groups: Props['groups'];
    predicates: Props['predicates'];
    noteMax: number;
}) {
    const [open, setOpen] = useState(false);
    const canAssess = editable && row.current && !row.is_self;
    const groupLabel =
        groups.find(
            (group) =>
                group.value === (row.assessment?.group ?? row.suggested_group),
        )?.label ?? '';

    return (
        <li className="space-y-2 p-4 text-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0 space-y-0.5">
                    <p className="font-medium break-words">{row.name}</p>
                    <p className="text-xs text-muted-foreground">
                        {[
                            row.employee_number,
                            row.positions.join(', ') || null,
                            groupLabel,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </p>
                    {!row.current && (
                        <p className="text-xs text-muted-foreground">
                            Sudah tidak ditempatkan di lembaga ini.
                        </p>
                    )}
                    {row.is_self && (
                        <p className="text-xs text-muted-foreground">
                            Kinerja Anda dinilai oleh penilai lain.
                        </p>
                    )}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {row.assessment ? (
                        <PredicateBadge assessment={row.assessment} />
                    ) : (
                        <Badge variant="outline">Belum dinilai</Badge>
                    )}
                    {canAssess && (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => setOpen(true)}
                        >
                            <ClipboardCheck />
                            {row.assessment ? 'Ubah nilai' : 'Nilai'}
                        </Button>
                    )}
                </div>
            </div>

            {row.assessment && (
                <div className="space-y-1 text-xs text-muted-foreground">
                    <p>
                        {row.assessment.scores
                            .map(
                                (item) =>
                                    `${item.name} ${item.score} (${item.weight}%)`,
                            )
                            .join(' · ')}
                    </p>
                    {row.assessment.strengths && (
                        <p className="break-words">
                            Kekuatan: {row.assessment.strengths}
                        </p>
                    )}
                    {row.assessment.suggestions && (
                        <p className="break-words">
                            Saran: {row.assessment.suggestions}
                        </p>
                    )}
                    <p>
                        Dinilai {formatDate(row.assessment.assessed_on)}
                        {row.assessment.assessed_by &&
                            ` oleh ${row.assessment.assessed_by}`}
                    </p>
                </div>
            )}

            {canAssess && (
                <FormDialog
                    open={open}
                    onOpenChange={setOpen}
                    title={`Nilai kinerja ${row.name}`}
                    description="Nilai bilangan bulat 0–100 per komponen. Nilai akhir dan predikat dihitung otomatis."
                    wide
                >
                    <AssessForm
                        row={row}
                        periodId={periodId}
                        components={components}
                        groups={groups}
                        predicates={predicates}
                        noteMax={noteMax}
                        onDone={() => setOpen(false)}
                    />
                </FormDialog>
            )}
        </li>
    );
}

function AssessForm({
    row,
    periodId,
    components,
    groups,
    predicates,
    noteMax,
    onDone,
}: {
    row: Row;
    periodId: string;
    components: Props['components'];
    groups: Props['groups'];
    predicates: Props['predicates'];
    noteMax: number;
    onDone: () => void;
}) {
    const [group, setGroup] = useState(
        row.assessment?.group ?? row.suggested_group,
    );
    const list = components[group] ?? [];

    // Nilai lama dipakai bila kelompok & nama komponen masih sama.
    const initial = (index: number): string => {
        const previous = row.assessment;

        if (
            previous &&
            previous.group === group &&
            previous.scores[index]?.name === list[index]?.name
        ) {
            return String(previous.scores[index].score);
        }

        return '';
    };

    const [scores, setScores] = useState<Record<string, string[]>>({});
    const values = scores[group] ?? list.map((_, index) => initial(index));
    const complete = values.every(
        (value) => /^\d{1,3}$/.test(value) && Number(value) <= 100,
    );
    const final = complete
        ? Math.round(
              values.reduce(
                  (sum, value, index) =>
                      sum + Number(value) * (list[index]?.weight ?? 0),
                  0,
              ) / 10,
          ) / 10
        : null;

    return (
        <Form
            action={`${BASE_URL}/periode/${periodId}/pegawai/${row.employee_id}`}
            method="post"
            errorBag={`assess-${row.employee_id}`}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-4"
        >
            {({ processing, errors }) => {
                const messages = errors as Record<string, string | undefined>;
                const scoreError = Object.entries(messages).find(([key]) =>
                    key.startsWith('scores'),
                )?.[1];

                return (
                    <>
                        <div className="grid gap-1.5">
                            <Label htmlFor={`group-${row.employee_id}`}>
                                Kelompok penilaian
                            </Label>
                            <select
                                id={`group-${row.employee_id}`}
                                name="group"
                                className={selectClass}
                                value={group}
                                onChange={(event) =>
                                    setGroup(event.target.value)
                                }
                            >
                                {groups.map((item) => (
                                    <option key={item.value} value={item.value}>
                                        {item.label}
                                    </option>
                                ))}
                            </select>
                            <p className="text-xs text-muted-foreground">
                                Usulan sistem:{' '}
                                {groups.find(
                                    (item) =>
                                        item.value === row.suggested_group,
                                )?.label ?? '-'}{' '}
                                (Guru bila punya jadwal mengajar).
                            </p>
                            <InputError message={messages.group} />
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2">
                            {list.map((component, index) => (
                                <div
                                    key={`${group}-${component.name}`}
                                    className="grid gap-1.5"
                                >
                                    <Label
                                        htmlFor={`score-${row.employee_id}-${index}`}
                                    >
                                        {component.name} ({component.weight}%)
                                    </Label>
                                    <Input
                                        id={`score-${row.employee_id}-${index}`}
                                        name={`scores[${index}]`}
                                        type="number"
                                        inputMode="numeric"
                                        min={0}
                                        max={100}
                                        step={1}
                                        required
                                        value={values[index] ?? ''}
                                        onChange={(event) => {
                                            const next = [...values];
                                            next[index] = event.target.value;
                                            setScores({
                                                ...scores,
                                                [group]: next,
                                            });
                                        }}
                                    />
                                </div>
                            ))}
                        </div>
                        <InputError message={scoreError} />

                        <div className="rounded-lg border border-dashed p-3 text-sm">
                            {final === null ? (
                                <span className="text-muted-foreground">
                                    Isi semua nilai untuk melihat nilai akhir.
                                </span>
                            ) : (
                                <span>
                                    Nilai akhir{' '}
                                    <strong>{scoreFormat.format(final)}</strong>{' '}
                                    · {predicateOf(final, predicates).label}
                                </span>
                            )}
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor={`strengths-${row.employee_id}`}>
                                Kekuatan (opsional)
                            </Label>
                            <textarea
                                id={`strengths-${row.employee_id}`}
                                name="strengths"
                                className={textareaClass}
                                maxLength={noteMax}
                                defaultValue={row.assessment?.strengths ?? ''}
                            />
                            <InputError message={messages.strengths} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor={`suggestions-${row.employee_id}`}>
                                Saran pengembangan (opsional)
                            </Label>
                            <textarea
                                id={`suggestions-${row.employee_id}`}
                                name="suggestions"
                                className={textareaClass}
                                maxLength={noteMax}
                                defaultValue={row.assessment?.suggestions ?? ''}
                            />
                            <InputError message={messages.suggestions} />
                        </div>

                        <InputError message={messages.form} />

                        <div className="flex flex-wrap gap-2">
                            <Button type="submit" disabled={processing}>
                                {processing ? 'Menyimpan…' : 'Simpan nilai'}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={onDone}
                            >
                                Batal
                            </Button>
                        </div>
                    </>
                );
            }}
        </Form>
    );
}

function PeriodsTab({ periods, limits }: Props) {
    const [adding, setAdding] = useState(false);

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm text-muted-foreground">
                    Penilaian bisa diisi sejak tanggal mulai periode.
                </p>
                <Button
                    type="button"
                    onClick={() => setAdding(true)}
                    className="w-full sm:w-auto"
                >
                    <Plus />
                    Tambah periode
                </Button>
            </div>

            <FormDialog
                open={adding}
                onOpenChange={setAdding}
                title="Tambah periode penilaian"
            >
                <PeriodForm
                    nameMax={limits.periodName}
                    onDone={() => setAdding(false)}
                />
            </FormDialog>

            {periods.length === 0 ? (
                <div className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                    Belum ada periode penilaian.
                </div>
            ) : (
                <ul className="divide-y rounded-xl border">
                    {periods.map((period) => (
                        <PeriodRow
                            key={period.id}
                            period={period}
                            nameMax={limits.periodName}
                        />
                    ))}
                </ul>
            )}
        </div>
    );
}

function PeriodRow({ period, nameMax }: { period: Period; nameMax: number }) {
    const [editing, setEditing] = useState(false);

    return (
        <li className="flex flex-wrap items-start justify-between gap-2 p-4 text-sm">
            <div className="min-w-0 space-y-0.5">
                <p className="font-medium break-words">{period.name}</p>
                <p className="text-xs text-muted-foreground">
                    {formatDate(period.start_date)} –{' '}
                    {formatDate(period.end_date)} · {period.assessed} pegawai
                    dinilai
                </p>
            </div>
            <div className="flex flex-wrap items-center gap-2">
                {period.locked ? (
                    <Badge variant="secondary">Dikunci</Badge>
                ) : !period.started ? (
                    <Badge variant="outline">Belum dimulai</Badge>
                ) : (
                    <Badge>Berjalan</Badge>
                )}
                {!period.locked && (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => setEditing(true)}
                    >
                        <Pencil />
                        Ubah
                    </Button>
                )}
                {!period.locked && period.assessed === 0 && (
                    <ConfirmAction
                        trigger={
                            <Button type="button" size="sm" variant="outline">
                                <Trash2 />
                                Hapus
                            </Button>
                        }
                        title={`Hapus periode ${period.name}?`}
                        description="Periode ini belum punya penilaian, jadi bisa dihapus."
                        action={`${BASE_URL}/periode/${period.id}`}
                        method="delete"
                        confirmLabel="Hapus periode"
                        errorBag={`period-remove-${period.id}`}
                    />
                )}
            </div>

            <FormDialog
                open={editing}
                onOpenChange={setEditing}
                title={`Ubah periode ${period.name}`}
            >
                <PeriodForm
                    period={period}
                    nameMax={nameMax}
                    onDone={() => setEditing(false)}
                />
            </FormDialog>
        </li>
    );
}

function PeriodForm({
    period,
    nameMax,
    onDone,
}: {
    period?: Period;
    nameMax: number;
    onDone: () => void;
}) {
    const key = period?.id ?? 'new';

    return (
        <Form
            action={
                period
                    ? `${BASE_URL}/periode/${period.id}`
                    : `${BASE_URL}/periode`
            }
            method={period ? 'patch' : 'post'}
            errorBag={`period-${key}`}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-3"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-1.5">
                        <Label htmlFor={`period-name-${key}`}>
                            Nama periode
                        </Label>
                        <Input
                            id={`period-name-${key}`}
                            name="name"
                            defaultValue={period?.name ?? ''}
                            maxLength={nameMax}
                            placeholder="mis. Semester Ganjil 2026/2027"
                            required
                        />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor={`period-start-${key}`}>
                                Tanggal mulai
                            </Label>
                            <Input
                                id={`period-start-${key}`}
                                name="start_date"
                                type="date"
                                defaultValue={period?.start_date ?? ''}
                                required
                            />
                            <InputError message={errors.start_date} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor={`period-end-${key}`}>
                                Tanggal selesai
                            </Label>
                            <Input
                                id={`period-end-${key}`}
                                name="end_date"
                                type="date"
                                defaultValue={period?.end_date ?? ''}
                                required
                            />
                            <InputError message={errors.end_date} />
                        </div>
                    </div>
                    <InputError
                        message={(errors as Record<string, string>).form}
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Menyimpan…' : 'Simpan periode'}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onDone}
                        >
                            Batal
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function ComponentsTab({ components, groups, limits }: Props) {
    return (
        <div className="space-y-3">
            <p className="text-sm text-muted-foreground">
                Mengubah komponen tidak mengubah penilaian yang sudah tersimpan;
                penilaian berikutnya memakai komponen baru.
            </p>
            <div className="grid gap-4 lg:grid-cols-2">
                {groups.map((group) => (
                    <ComponentCard
                        key={group.value}
                        group={group}
                        components={components[group.value] ?? []}
                        limits={limits}
                    />
                ))}
            </div>
        </div>
    );
}

function ComponentCard({
    group,
    components,
    limits,
}: {
    group: Props['groups'][number];
    components: Component[];
    limits: Props['limits'];
}) {
    const [editing, setEditing] = useState(false);

    return (
        <section className="space-y-3 rounded-xl border p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-base font-medium">{group.label}</h2>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => setEditing(true)}
                >
                    <Pencil />
                    Ubah komponen
                </Button>
            </div>
            <ul className="divide-y rounded-lg border text-sm">
                {components.map((component) => (
                    <li
                        key={component.name}
                        className="flex items-center justify-between gap-2 px-3 py-2"
                    >
                        <span className="break-words">{component.name}</span>
                        <span className="text-muted-foreground tabular-nums">
                            {component.weight}%
                        </span>
                    </li>
                ))}
            </ul>

            <FormDialog
                open={editing}
                onOpenChange={setEditing}
                title={`Komponen penilaian ${group.label.toLowerCase()}`}
                description="Jumlah bobot harus tepat 100%."
                wide
            >
                <ComponentsForm
                    group={group}
                    initial={components}
                    limits={limits}
                    onDone={() => setEditing(false)}
                />
            </FormDialog>
        </section>
    );
}

function ComponentsForm({
    group,
    initial,
    limits,
    onDone,
}: {
    group: Props['groups'][number];
    initial: Component[];
    limits: Props['limits'];
    onDone: () => void;
}) {
    const [rows, setRows] = useState(() =>
        initial.map((item, index) => ({
            key: index,
            name: item.name,
            weight: String(item.weight),
        })),
    );
    const [nextKey, setNextKey] = useState(initial.length);
    const total = rows.reduce((sum, row) => sum + (Number(row.weight) || 0), 0);

    const update = (key: number, field: 'name' | 'weight', value: string) =>
        setRows(
            rows.map((row) =>
                row.key === key ? { ...row, [field]: value } : row,
            ),
        );

    return (
        <Form
            action={`${BASE_URL}/komponen/${group.slug}`}
            method="post"
            errorBag={`components-${group.slug}`}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-3"
        >
            {({ processing, errors }) => (
                <>
                    <ul className="space-y-2">
                        {rows.map((row, index) => (
                            <li
                                key={row.key}
                                className="grid grid-cols-[minmax(0,1fr)_5.5rem_auto] items-end gap-2"
                            >
                                <div className="grid gap-1.5">
                                    <Label
                                        htmlFor={`component-name-${group.slug}-${row.key}`}
                                        className={
                                            index > 0 ? 'sr-only' : undefined
                                        }
                                    >
                                        Komponen
                                    </Label>
                                    <Input
                                        id={`component-name-${group.slug}-${row.key}`}
                                        name={`components[${index}][name]`}
                                        value={row.name}
                                        maxLength={limits.componentName}
                                        required
                                        onChange={(event) =>
                                            update(
                                                row.key,
                                                'name',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label
                                        htmlFor={`component-weight-${group.slug}-${row.key}`}
                                        className={
                                            index > 0 ? 'sr-only' : undefined
                                        }
                                    >
                                        Bobot (%)
                                    </Label>
                                    <Input
                                        id={`component-weight-${group.slug}-${row.key}`}
                                        name={`components[${index}][weight]`}
                                        type="number"
                                        inputMode="numeric"
                                        min={1}
                                        max={100}
                                        step={1}
                                        value={row.weight}
                                        required
                                        onChange={(event) =>
                                            update(
                                                row.key,
                                                'weight',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Hapus komponen ${row.name || index + 1}`}
                                    disabled={rows.length <= 1}
                                    onClick={() =>
                                        setRows(
                                            rows.filter(
                                                (item) => item.key !== row.key,
                                            ),
                                        )
                                    }
                                >
                                    <X />
                                </Button>
                            </li>
                        ))}
                    </ul>

                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={rows.length >= limits.components}
                            onClick={() => {
                                setRows([
                                    ...rows,
                                    { key: nextKey, name: '', weight: '' },
                                ]);
                                setNextKey(nextKey + 1);
                            }}
                        >
                            <Plus />
                            Tambah komponen
                        </Button>
                        <span
                            className={
                                total === 100
                                    ? 'text-sm text-muted-foreground'
                                    : 'text-sm text-destructive'
                            }
                        >
                            Jumlah bobot: {total}%
                        </span>
                    </div>

                    <InputError
                        message={(errors as Record<string, string>).components}
                    />

                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Menyimpan…' : 'Simpan komponen'}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onDone}
                        >
                            Batal
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
