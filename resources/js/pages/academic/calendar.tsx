import { Form, Head, Link } from '@inertiajs/react';
import {
    CalendarCheck,
    CalendarPlus,
    Lock,
    Pencil,
    Plus,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import {
    AttendanceModeSection,
    type AttendanceModes,
} from '@/components/academic/attendance-mode-section';
import {
    GradingSection,
    type Grading,
} from '@/components/academic/grading-section';
import {
    HolidayList,
    WeeklyOffForm,
    type HolidayRow,
} from '@/components/academic/holiday-actions';
import { FormDialog } from '@/components/form-dialog';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { PageTabs, usePageTab } from '@/components/page-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type CalendarSummary = {
    id: string;
    name: string;
    is_default: boolean;
    years: number;
    current_year: string | null;
};

type TermRow = {
    sequence: number;
    name: string;
    start_date: string;
    end_date: string;
};

type YearRow = {
    id: string;
    name: string;
    start_date: string;
    end_date: string;
    is_current: boolean;
    terms: TermRow[];
    holidays: HolidayRow[];
};

type RuleRow = {
    id: string;
    target: string;
    calendar: string;
    enforced: boolean;
};

type UsageRow = {
    id: string;
    name: string;
    calendar: string | null;
    current_year: string | null;
    source: string;
};

type Props = {
    calendars: CalendarSummary[];
    selected: {
        id: string;
        name: string;
        is_default: boolean;
        weekly_off_days: number[];
        years: YearRow[];
    };
    dayOptions: Option[];
    rules: RuleRow[];
    usage: UsageRow[];
    options: { jenjang: Option[]; organizations: Option[] };
    attendanceModes: AttendanceModes;
    grading: Grading;
    today: string;
    limits: { maxTerms: number; maxMonths: number };
};

type TermDraft = { name: string; start_date: string; end_date: string };

type YearDraft = {
    name: string;
    start_date: string;
    end_date: string;
    terms: TermDraft[];
};

const BASE_URL = '/akademik/kalender';

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 dark:bg-input/30';

const dateFormatter = new Intl.DateTimeFormat('id-ID', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    timeZone: 'UTC',
});

/** Tanggal "YYYY-MM-DD" → "13 Jul 2026" (dibaca sebagai UTC agar tidak bergeser sehari). */
function formatDate(value: string): string {
    return dateFormatter.format(new Date(`${value}T00:00:00Z`));
}

/** Tambah n tahun ke tanggal "YYYY-MM-DD"; 29 Feb menjadi 28 Feb bila perlu. */
function shiftYears(value: string, years: number): string {
    const [year, month, day] = value.split('-').map(Number);
    const target = new Date(Date.UTC(year + years, month - 1, day));

    if (target.getUTCMonth() !== month - 1) {
        target.setUTCDate(0);
    }

    return target.toISOString().slice(0, 10);
}

/**
 * Usulan isian tahun ajaran baru: lanjutan tahun terakhir (semua tanggal +1 tahun),
 * atau Juli–Juni dua semester yang memuat hari ini. Admin tetap bisa mengubah semuanya.
 */
function suggestYear(latest: YearRow | undefined, today: string): YearDraft {
    if (latest) {
        const first = Number(latest.name.slice(0, 4)) + 1;

        return {
            name: `${first}/${first + 1}`,
            start_date: shiftYears(latest.start_date, 1),
            end_date: shiftYears(latest.end_date, 1),
            terms: latest.terms.map((term) => ({
                name: term.name,
                start_date: shiftYears(term.start_date, 1),
                end_date: shiftYears(term.end_date, 1),
            })),
        };
    }

    const [year, month] = today.split('-').map(Number);
    const first = month >= 7 ? year : year - 1;

    return {
        name: `${first}/${first + 1}`,
        start_date: `${first}-07-01`,
        end_date: `${first + 1}-06-30`,
        terms: [
            {
                name: 'Semester Ganjil',
                start_date: `${first}-07-01`,
                end_date: `${first}-12-31`,
            },
            {
                name: 'Semester Genap',
                start_date: `${first + 1}-01-01`,
                end_date: `${first + 1}-06-30`,
            },
        ],
    };
}

function draftOf(year: YearRow): YearDraft {
    return {
        name: year.name,
        start_date: year.start_date,
        end_date: year.end_date,
        terms: year.terms.map((term) => ({
            name: term.name,
            start_date: term.start_date,
            end_date: term.end_date,
        })),
    };
}

const TABS = ['tahun', 'pemakaian', 'presensi', 'penilaian'] as const;

/**
 * Halaman Kalender akademik (PRD-002 §4.4). Hak akses & semua aturan tanggal tetap dicek server;
 * kalender dan tahun ajaran tidak dihapus, hanya diubah.
 */
export default function AcademicCalendar({
    calendars,
    selected,
    dayOptions,
    rules,
    usage,
    options,
    attendanceModes,
    grading,
    today,
    limits,
}: Props) {
    const [tab, setTab] = usePageTab(TABS, 'tahun');
    const [creatingYear, setCreatingYear] = useState(false);
    const [creatingCalendar, setCreatingCalendar] = useState(false);

    return (
        <>
            <Head title="Kalender akademik" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Kalender akademik"
                    description="Tahun ajaran dan semester untuk seluruh lembaga. Lembaga memakai Kalender Yayasan kecuali diatur lain di Pemakaian kalender."
                />

                <PageTabs
                    label="Bagian kalender akademik"
                    tabs={[
                        { value: 'tahun', label: 'Tahun ajaran' },
                        { value: 'pemakaian', label: 'Pemakaian kalender' },
                        { value: 'presensi', label: 'Mode presensi' },
                        { value: 'penilaian', label: 'Penilaian' },
                    ]}
                    value={tab}
                    onChange={setTab}
                />

                {tab === 'tahun' && (
                    <>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <p className="text-sm text-muted-foreground">
                                Pilih kalender untuk melihat tahun ajarannya.
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setCreatingCalendar(true)}
                            >
                                <Plus />
                                Tambah kalender
                            </Button>
                        </div>

                        <FormDialog
                            open={creatingCalendar}
                            onOpenChange={setCreatingCalendar}
                            title="Tambah kalender"
                            description="Untuk lembaga dengan periode belajar berbeda, mis. Kalender MDA. Kalender tidak bisa dihapus."
                        >
                            <CreateCalendarForm
                                onDone={() => setCreatingCalendar(false)}
                            />
                        </FormDialog>

                        {/* Grid otomatis: kartu kalender yang tidak muat di satu baris pindah ke baris berikutnya (HP). */}
                        <nav
                            aria-label="Kalender"
                            className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-2"
                        >
                            {calendars.map((calendar) => {
                                const active = calendar.id === selected.id;

                                return (
                                    <Link
                                        key={calendar.id}
                                        href={`${BASE_URL}?kalender=${calendar.id}`}
                                        preserveScroll
                                        aria-current={
                                            active ? 'page' : undefined
                                        }
                                        className={
                                            'flex min-h-14 flex-col justify-center gap-0.5 rounded-md border px-3 py-2 text-sm transition-colors ' +
                                            (active
                                                ? 'border-primary bg-primary text-primary-foreground'
                                                : 'hover:bg-muted')
                                        }
                                    >
                                        <span className="font-medium break-words">
                                            {calendar.name}
                                        </span>
                                        <span
                                            className={
                                                'text-xs ' +
                                                (active
                                                    ? 'text-primary-foreground/80'
                                                    : 'text-muted-foreground')
                                            }
                                        >
                                            {calendar.is_default
                                                ? 'Bawaan · '
                                                : ''}
                                            {calendar.current_year
                                                ? `Berjalan: ${calendar.current_year}`
                                                : 'Belum ada tahun berjalan'}
                                        </span>
                                    </Link>
                                );
                            })}
                        </nav>

                        <section
                            aria-label={`Tahun ajaran ${selected.name}`}
                            className="space-y-4"
                        >
                            <CalendarHeader
                                key={selected.id}
                                calendar={selected}
                            />
                            <WeeklyOffForm
                                key={`off-${selected.id}`}
                                calendarId={selected.id}
                                days={selected.weekly_off_days}
                                options={dayOptions}
                            />

                            <Button
                                type="button"
                                onClick={() => setCreatingYear(true)}
                            >
                                <CalendarPlus />
                                Tambah tahun ajaran
                            </Button>

                            <FormDialog
                                open={creatingYear}
                                onOpenChange={setCreatingYear}
                                title={`Tambah tahun ajaran — ${selected.name}`}
                                wide
                            >
                                <YearForm
                                    key={selected.id}
                                    action={`${BASE_URL}/${selected.id}/tahun-ajaran`}
                                    method="post"
                                    errorBag="create-year"
                                    initial={suggestYear(
                                        selected.years[0],
                                        today,
                                    )}
                                    limits={limits}
                                    onDone={() => setCreatingYear(false)}
                                />
                            </FormDialog>

                            {selected.years.length === 0 ? (
                                <p className="rounded-xl border p-4 text-sm text-muted-foreground">
                                    Kalender ini belum punya tahun ajaran. Tahun
                                    ajaran pertama otomatis menjadi tahun
                                    berjalan.
                                </p>
                            ) : (
                                <ul className="space-y-3">
                                    {selected.years.map((year) => (
                                        <YearCard
                                            key={year.id}
                                            year={year}
                                            limits={limits}
                                        />
                                    ))}
                                </ul>
                            )}
                        </section>
                    </>
                )}

                {tab === 'pemakaian' && (
                    <>
                        <section
                            aria-label="Pemakaian kalender"
                            className="space-y-4"
                        >
                            <Heading
                                variant="small"
                                title="Pemakaian kalender"
                                description="Tetapkan kalender lain untuk satu jenjang (mis. semua MDA) atau untuk lembaga/unit tertentu. Pengaturan di lembaga/unit menang atas pengaturan jenjang, kecuali pengaturan jenjang dikunci."
                            />

                            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                                <div className="rounded-xl border p-4">
                                    <AssignForm
                                        calendars={calendars}
                                        options={options}
                                    />
                                </div>

                                <div className="rounded-xl border">
                                    {rules.length === 0 ? (
                                        <p className="p-4 text-sm text-muted-foreground">
                                            Belum ada pengaturan. Semua lembaga
                                            memakai kalender bawaan.
                                        </p>
                                    ) : (
                                        <ul className="divide-y">
                                            {rules.map((rule) => (
                                                <li
                                                    key={rule.id}
                                                    className="flex flex-wrap items-center justify-between gap-2 p-4 text-sm"
                                                >
                                                    <span className="font-medium break-words">
                                                        {rule.target}
                                                    </span>
                                                    <span className="flex flex-wrap items-center gap-1.5 text-muted-foreground">
                                                        {rule.calendar}
                                                        {rule.enforced && (
                                                            <Badge variant="outline">
                                                                <Lock />
                                                                Dikunci
                                                            </Badge>
                                                        )}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            </div>
                        </section>

                        <section
                            aria-label="Kalender per lembaga"
                            className="space-y-4"
                        >
                            <Heading
                                variant="small"
                                title="Kalender per lembaga"
                                description="Kalender dan tahun ajaran berjalan yang dipakai setiap lembaga aktif saat ini."
                            />
                            <UsageTable rows={usage} />
                        </section>
                    </>
                )}

                {tab === 'presensi' && (
                    <AttendanceModeSection
                        data={attendanceModes}
                        options={options}
                    />
                )}

                {tab === 'penilaian' && (
                    <GradingSection data={grading} options={options} />
                )}
            </div>
        </>
    );
}

function CalendarHeader({ calendar }: { calendar: Props['selected'] }) {
    const [editing, setEditing] = useState(false);

    if (editing) {
        return (
            <Form
                action={`${BASE_URL}/${calendar.id}`}
                method="patch"
                errorBag="rename-calendar"
                options={{ preserveScroll: true }}
                onSuccess={() => setEditing(false)}
                className="space-y-2"
            >
                {({ processing, errors }) => (
                    <>
                        <Label htmlFor="calendar-rename">Nama kalender</Label>
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <Input
                                id="calendar-rename"
                                name="name"
                                defaultValue={calendar.name}
                                maxLength={100}
                                required
                                autoFocus
                            />
                            <div className="flex gap-2">
                                <Button type="submit" disabled={processing}>
                                    Simpan
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setEditing(false)}
                                >
                                    Batal
                                </Button>
                            </div>
                        </div>
                        <InputError message={errors.name ?? errors.form} />
                    </>
                )}
            </Form>
        );
    }

    return (
        <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="flex flex-wrap items-center gap-2">
                <h3 className="text-lg font-semibold break-words">
                    {calendar.name}
                </h3>
                {calendar.is_default && (
                    <Badge variant="secondary">Bawaan</Badge>
                )}
            </div>
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => setEditing(true)}
            >
                <Pencil />
                Ubah nama
            </Button>
        </div>
    );
}

function YearCard({
    year,
    limits,
}: {
    year: YearRow;
    limits: Props['limits'];
}) {
    const [editing, setEditing] = useState(false);

    return (
        <li className="space-y-3 rounded-xl border p-4">
            {editing ? (
                <YearForm
                    title={`Ubah tahun ajaran ${year.name}`}
                    action={`/akademik/tahun-ajaran/${year.id}`}
                    method="patch"
                    errorBag={`year-${year.id}`}
                    initial={draftOf(year)}
                    limits={limits}
                    onDone={() => setEditing(false)}
                />
            ) : (
                <>
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <div className="space-y-1">
                            <p className="flex flex-wrap items-center gap-2 font-medium">
                                Tahun ajaran {year.name}
                                {year.is_current && <Badge>Berjalan</Badge>}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {formatDate(year.start_date)} –{' '}
                                {formatDate(year.end_date)}
                            </p>
                        </div>

                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setEditing(true)}
                            >
                                <Pencil />
                                Ubah
                            </Button>
                            {!year.is_current && (
                                <Form
                                    action={`/akademik/tahun-ajaran/${year.id}/berjalan`}
                                    method="post"
                                    errorBag={`current-${year.id}`}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <Button
                                                type="submit"
                                                variant="outline"
                                                size="sm"
                                                disabled={processing}
                                            >
                                                <CalendarCheck />
                                                Jadikan tahun berjalan
                                            </Button>
                                            <InputError message={errors.form} />
                                        </>
                                    )}
                                </Form>
                            )}
                        </div>
                    </div>

                    <ol className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-2">
                        {year.terms.map((term) => (
                            <li
                                key={term.sequence}
                                className="rounded-md bg-muted/50 px-3 py-2 text-sm"
                            >
                                <p className="font-medium">{term.name}</p>
                                <p className="text-muted-foreground">
                                    {formatDate(term.start_date)} –{' '}
                                    {formatDate(term.end_date)}
                                </p>
                            </li>
                        ))}
                    </ol>

                    <HolidayList year={year} />
                </>
            )}
        </li>
    );
}

function YearForm({
    title,
    action,
    method,
    errorBag,
    initial,
    limits,
    onDone,
}: {
    title?: string;
    action: string;
    method: 'post' | 'patch';
    errorBag: string;
    initial: YearDraft;
    limits: Props['limits'];
    onDone: () => void;
}) {
    // Baris semester dikelola di state agar bisa ditambah/dihapus; nilai isian tetap dari form HTML.
    const [terms, setTerms] = useState<TermDraft[]>(initial.terms);
    const [termKeys, setTermKeys] = useState<number[]>(() =>
        initial.terms.map((_, index) => index),
    );
    const [nextKey, setNextKey] = useState(initial.terms.length);

    const addTerm = () => {
        setTerms([...terms, { name: '', start_date: '', end_date: '' }]);
        setTermKeys([...termKeys, nextKey]);
        setNextKey(nextKey + 1);
    };

    const removeTerm = (index: number) => {
        setTerms(terms.filter((_, position) => position !== index));
        setTermKeys(termKeys.filter((_, position) => position !== index));
    };

    return (
        <Form
            action={action}
            method={method}
            errorBag={errorBag}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-4"
        >
            {({ processing, errors }) => (
                <>
                    {title && <h4 className="font-medium">{title}</h4>}

                    <div className="grid gap-3 sm:grid-cols-3">
                        <div className="grid gap-1.5">
                            <Label htmlFor={`${errorBag}-name`}>
                                Nama tahun ajaran
                            </Label>
                            <Input
                                id={`${errorBag}-name`}
                                name="name"
                                defaultValue={initial.name}
                                placeholder="2026/2027"
                                maxLength={9}
                                inputMode="numeric"
                                required
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor={`${errorBag}-start`}>
                                Tanggal mulai
                            </Label>
                            <Input
                                id={`${errorBag}-start`}
                                type="date"
                                name="start_date"
                                defaultValue={initial.start_date}
                                required
                            />
                            <InputError message={errors.start_date} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor={`${errorBag}-end`}>
                                Tanggal selesai
                            </Label>
                            <Input
                                id={`${errorBag}-end`}
                                type="date"
                                name="end_date"
                                defaultValue={initial.end_date}
                                required
                            />
                            <InputError message={errors.end_date} />
                        </div>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Bentuk nama TTTT/TTTT, tahun kedua = tahun pertama + 1
                        (boleh tahun Hijriah, mis. 1447/1448). Paling lama{' '}
                        {limits.maxMonths} bulan.
                    </p>

                    <fieldset className="space-y-3">
                        <legend className="text-sm font-medium">
                            Semester ({terms.length}/{limits.maxTerms})
                        </legend>

                        {terms.map((term, index) => (
                            <div
                                key={termKeys[index]}
                                className="grid gap-2 rounded-md border p-3 sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-start"
                            >
                                <div className="grid gap-1.5">
                                    <Label
                                        htmlFor={`${errorBag}-term-${termKeys[index]}-name`}
                                    >
                                        Semester {index + 1}
                                    </Label>
                                    <Input
                                        id={`${errorBag}-term-${termKeys[index]}-name`}
                                        name={`terms[${index}][name]`}
                                        defaultValue={term.name}
                                        placeholder="Semester Ganjil"
                                        maxLength={50}
                                        required
                                    />
                                    <InputError
                                        message={errors[`terms.${index}.name`]}
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label
                                        htmlFor={`${errorBag}-term-${termKeys[index]}-start`}
                                    >
                                        Mulai
                                    </Label>
                                    <Input
                                        id={`${errorBag}-term-${termKeys[index]}-start`}
                                        type="date"
                                        name={`terms[${index}][start_date]`}
                                        defaultValue={term.start_date}
                                        required
                                    />
                                    <InputError
                                        message={
                                            errors[`terms.${index}.start_date`]
                                        }
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label
                                        htmlFor={`${errorBag}-term-${termKeys[index]}-end`}
                                    >
                                        Selesai
                                    </Label>
                                    <Input
                                        id={`${errorBag}-term-${termKeys[index]}-end`}
                                        type="date"
                                        name={`terms[${index}][end_date]`}
                                        defaultValue={term.end_date}
                                        required
                                    />
                                    <InputError
                                        message={
                                            errors[`terms.${index}.end_date`]
                                        }
                                    />
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="sm:mt-6"
                                    disabled={terms.length <= 1}
                                    onClick={() => removeTerm(index)}
                                    aria-label={`Hapus semester ${index + 1}`}
                                >
                                    <Trash2 />
                                    <span className="sm:sr-only">Hapus</span>
                                </Button>
                            </div>
                        ))}

                        <InputError message={errors.terms} />

                        {terms.length < limits.maxTerms && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={addTerm}
                            >
                                <Plus />
                                Tambah semester
                            </Button>
                        )}
                    </fieldset>

                    <InputError message={errors.form} />

                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Menyimpan…' : 'Simpan'}
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

function CreateCalendarForm({ onDone }: { onDone: () => void }) {
    return (
        <Form
            action={BASE_URL}
            method="post"
            errorBag="create-calendar"
            resetOnSuccess
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-3"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-1.5">
                        <Label htmlFor="calendar-name">Nama kalender</Label>
                        <Input
                            id="calendar-name"
                            name="name"
                            placeholder="mis. Kalender MDA"
                            maxLength={100}
                            required
                        />
                        <InputError message={errors.name ?? errors.form} />
                    </div>
                    <Button type="submit" disabled={processing}>
                        <Plus />
                        Tambah
                    </Button>
                </>
            )}
        </Form>
    );
}

function AssignForm({
    calendars,
    options,
}: {
    calendars: CalendarSummary[];
    options: Props['options'];
}) {
    const [target, setTarget] = useState<'jenjang' | 'lembaga'>('jenjang');

    return (
        <Form
            action={`${BASE_URL}/pemakaian`}
            method="post"
            errorBag="assign"
            options={{ preserveScroll: true }}
            className="space-y-3"
        >
            {({ processing, errors }) => (
                <>
                    <h3 className="text-sm font-medium">Atur pemakaian</h3>

                    {/* Grid otomatis agar pilihan turun baris di HP. */}
                    <div
                        role="radiogroup"
                        aria-label="Berlaku untuk"
                        className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2"
                    >
                        {(
                            [
                                ['jenjang', 'Satu jenjang'],
                                ['lembaga', 'Lembaga / unit'],
                            ] as const
                        ).map(([value, label]) => (
                            <label
                                key={value}
                                className={
                                    'flex min-h-10 cursor-pointer items-center gap-2 rounded-md border px-3 text-sm ' +
                                    (target === value
                                        ? 'border-primary bg-primary/5'
                                        : 'hover:bg-muted')
                                }
                            >
                                <input
                                    type="radio"
                                    name="target"
                                    value={value}
                                    checked={target === value}
                                    onChange={() => setTarget(value)}
                                    className="size-4 accent-primary"
                                />
                                {label}
                            </label>
                        ))}
                    </div>
                    <InputError message={errors.target} />

                    {target === 'jenjang' ? (
                        <div className="grid gap-1.5">
                            <Label htmlFor="assign-jenjang">Jenjang</Label>
                            <select
                                id="assign-jenjang"
                                name="jenjang"
                                className={selectClass}
                                defaultValue=""
                                required
                            >
                                <option value="" disabled>
                                    Pilih jenjang
                                </option>
                                {options.jenjang.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.jenjang} />
                        </div>
                    ) : (
                        <div className="grid gap-1.5">
                            <Label htmlFor="assign-organization">
                                Lembaga / unit
                            </Label>
                            <select
                                id="assign-organization"
                                name="organization_id"
                                className={selectClass}
                                defaultValue=""
                                required
                            >
                                <option value="" disabled>
                                    Pilih lembaga atau unit
                                </option>
                                {options.organizations.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.organization_id} />
                        </div>
                    )}

                    <div className="grid gap-1.5">
                        <Label htmlFor="assign-calendar">Kalender</Label>
                        <select
                            id="assign-calendar"
                            name="calendar_id"
                            className={selectClass}
                            defaultValue=""
                            required
                        >
                            <option value="" disabled>
                                Pilih kalender
                            </option>
                            {calendars.map((calendar) => (
                                <option key={calendar.id} value={calendar.id}>
                                    {calendar.name}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.calendar_id} />
                    </div>

                    <label className="flex cursor-pointer items-start gap-2 text-sm">
                        <input
                            type="checkbox"
                            name="enforced"
                            value="1"
                            className="mt-0.5 size-4 accent-primary"
                        />
                        Kunci: lembaga di bawahnya tidak bisa memakai kalender
                        lain.
                    </label>

                    <Button type="submit" disabled={processing}>
                        Simpan pemakaian
                    </Button>
                </>
            )}
        </Form>
    );
}

function UsageTable({ rows }: { rows: UsageRow[] }) {
    if (rows.length === 0) {
        return (
            <p className="rounded-xl border p-4 text-sm text-muted-foreground">
                Belum ada lembaga aktif.
            </p>
        );
    }

    return (
        <>
            {/* Desktop: tabel. HP: kartu. */}
            <div className="hidden overflow-hidden rounded-xl border md:block">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="px-4 py-2 font-medium">Lembaga</th>
                            <th className="px-4 py-2 font-medium">Kalender</th>
                            <th className="px-4 py-2 font-medium">
                                Tahun berjalan
                            </th>
                            <th className="px-4 py-2 font-medium">Sumber</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {rows.map((row) => (
                            <tr key={row.id}>
                                <td className="px-4 py-2">{row.name}</td>
                                <td className="px-4 py-2">
                                    {row.calendar ?? '—'}
                                </td>
                                <td className="px-4 py-2">
                                    {row.current_year ?? (
                                        <span className="text-muted-foreground">
                                            Belum ada
                                        </span>
                                    )}
                                </td>
                                <td className="px-4 py-2">
                                    <SourceBadge source={row.source} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <ul className="space-y-2 md:hidden">
                {rows.map((row) => (
                    <li key={row.id} className="rounded-xl border p-3 text-sm">
                        <p className="font-medium">{row.name}</p>
                        <p className="text-muted-foreground">
                            {row.calendar ?? '—'} ·{' '}
                            {row.current_year ?? 'belum ada tahun berjalan'}
                        </p>
                        <div className="mt-1">
                            <SourceBadge source={row.source} />
                        </div>
                    </li>
                ))}
            </ul>
        </>
    );
}

function SourceBadge({ source }: { source: string }) {
    if (source === 'Dikunci') {
        return (
            <Badge variant="outline">
                <Lock />
                Dikunci
            </Badge>
        );
    }

    return (
        <Badge variant={source === 'Diatur' ? 'secondary' : 'outline'}>
            {source}
        </Badge>
    );
}
