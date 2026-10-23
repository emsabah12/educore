import { Form, Head, router } from '@inertiajs/react';
import { Lock, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type LeaveType = {
    id: string;
    code: string;
    name: string;
    has_quota: boolean;
    annual_quota: number | null;
    max_days: number | null;
    is_active: boolean;
};

type Holiday = {
    id: string;
    name: string;
    start_date: string;
    end_date: string;
    days: number;
};

type Props = {
    types: LeaveType[];
    weeklyOff: {
        rules: {
            id: string;
            target: string;
            days: string;
            enforced: boolean;
        }[];
        usage: { id: string; name: string; days: string; source: string }[];
    };
    workHours: {
        rules: {
            id: string;
            target: string;
            days: string;
            enforced: boolean;
        }[];
        usage: { id: string; name: string; days: string; source: string }[];
    };
    approval: {
        rules: {
            id: string;
            target: string;
            days: string;
            enforced: boolean;
        }[];
        usage: { id: string; name: string; days: string; source: string }[];
    };
    holidays: Holiday[];
    dayOptions: Option[];
    options: { jenjang: Option[]; organizations: Option[] };
    year: number;
    limits: { maxDays: number };
};

const BASE_URL = '/pegawai/cuti';

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 dark:bg-input/30';

const dateFormatter = new Intl.DateTimeFormat('id-ID', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    timeZone: 'UTC',
});

function formatDate(value: string): string {
    return dateFormatter.format(new Date(`${value}T00:00:00Z`));
}

/**
 * Pengaturan cuti (PRD-001 §13.2, HR-3a): jenis cuti, libur mingguan kalender kerja per
 * jenjang/lembaga, dan hari libur yayasan. Hanya Admin SDM / Admin Yayasan; semua aturan dicek server.
 */
export default function LeaveSettings(props: Props) {
    return (
        <>
            <Head title="Pengaturan cuti & presensi" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Pengaturan cuti & presensi"
                    description="Jenis cuti & jatahnya, kalender kerja (libur mingguan dan tanggal merah, terpisah dari kalender akademik), alur persetujuan cuti, dan jam kerja untuk presensi pegawai."
                />

                <TypesSection
                    types={props.types}
                    maxDays={props.limits.maxDays}
                />
                <WeeklyOffSection {...props} />
                <ApprovalSection {...props} />
                <WorkHoursSection {...props} />
                <HolidaysSection holidays={props.holidays} year={props.year} />
            </div>
        </>
    );
}

// ── Jenis cuti ───────────────────────────────────────────────────────────────

function TypesSection({
    types,
    maxDays,
}: {
    types: LeaveType[];
    maxDays: number;
}) {
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<string | null>(null);

    return (
        <section aria-label="Jenis cuti" className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <Heading
                    variant="small"
                    title="Jenis cuti & izin"
                    description="Berjatah = punya jatah hari per tahun kalender; sisa jatah hangus di akhir tahun."
                />
                <Button
                    type="button"
                    variant={adding ? 'default' : 'outline'}
                    onClick={() => setAdding((current) => !current)}
                    className="w-full sm:w-auto"
                >
                    <Plus />
                    Tambah jenis cuti
                </Button>
            </div>

            {adding && (
                <TypeForm maxDays={maxDays} onDone={() => setAdding(false)} />
            )}

            <ul className="divide-y rounded-xl border">
                {types.length === 0 && (
                    <li className="p-4 text-sm text-muted-foreground">
                        Belum ada jenis cuti.
                    </li>
                )}
                {types.map((type) => (
                    <li key={type.id} className="space-y-3 p-4">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div className="min-w-0 space-y-1 text-sm">
                                <p className="flex flex-wrap items-center gap-2 font-medium">
                                    {type.name}
                                    <Badge variant="outline">{type.code}</Badge>
                                    {!type.is_active && (
                                        <Badge variant="secondary">
                                            Nonaktif
                                        </Badge>
                                    )}
                                </p>
                                <p className="text-muted-foreground">
                                    {type.has_quota
                                        ? `Jatah ${type.annual_quota} hari per tahun`
                                        : 'Tanpa jatah'}
                                    {type.max_days
                                        ? ` · maks. ${type.max_days} hari per pengajuan`
                                        : ''}
                                </p>
                            </div>
                            <div className="grid w-full grid-cols-[repeat(auto-fit,minmax(8rem,1fr))] gap-2 sm:flex sm:w-auto">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant={
                                        editing === type.id
                                            ? 'default'
                                            : 'outline'
                                    }
                                    onClick={() =>
                                        setEditing((current) =>
                                            current === type.id
                                                ? null
                                                : type.id,
                                        )
                                    }
                                >
                                    <Pencil />
                                    Ubah
                                </Button>
                                <Form
                                    action={`${BASE_URL}/jenis/${type.id}/${type.is_active ? 'nonaktifkan' : 'aktifkan'}`}
                                    method="post"
                                    errorBag={`leave-type-status-${type.id}`}
                                    options={{ preserveScroll: true }}
                                    className="grid"
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            {type.is_active
                                                ? 'Nonaktifkan'
                                                : 'Aktifkan'}
                                        </Button>
                                    )}
                                </Form>
                            </div>
                        </div>
                        {editing === type.id && (
                            <TypeForm
                                type={type}
                                maxDays={maxDays}
                                onDone={() => setEditing(null)}
                            />
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}

function TypeForm({
    type,
    maxDays,
    onDone,
}: {
    type?: LeaveType;
    maxDays: number;
    onDone: () => void;
}) {
    const [hasQuota, setHasQuota] = useState(type?.has_quota ?? false);
    const prefix = type ? `lt-${type.id}` : 'lt-new';

    return (
        <Form
            action={type ? `${BASE_URL}/jenis/${type.id}` : `${BASE_URL}/jenis`}
            method={type ? 'patch' : 'post'}
            errorBag={type ? `leave-type-${type.id}` : 'leave-type-new'}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-3 rounded-xl border border-primary/30 bg-muted/20 p-4"
        >
            {({ processing, errors }) => (
                <>
                    <h3 className="text-sm font-medium">
                        {type ? `Ubah ${type.name}` : 'Tambah jenis cuti'}
                    </h3>
                    <InputError message={errors.form} />
                    <div className="grid gap-3 sm:grid-cols-2">
                        {!type && (
                            <div className="grid content-start gap-1.5">
                                <Label htmlFor={`${prefix}-code`}>Kode</Label>
                                <Input
                                    id={`${prefix}-code`}
                                    name="code"
                                    maxLength={30}
                                    placeholder="mis. CUTI-HAJI"
                                    required
                                />
                                <InputError message={errors.code} />
                            </div>
                        )}
                        <div className="grid content-start gap-1.5">
                            <Label htmlFor={`${prefix}-name`}>Nama</Label>
                            <Input
                                id={`${prefix}-name`}
                                name="name"
                                defaultValue={type?.name ?? ''}
                                maxLength={100}
                                required
                            />
                            <InputError message={errors.name} />
                        </div>
                        {!type && (
                            <label className="flex cursor-pointer items-center gap-2 self-end text-sm sm:col-span-2">
                                <input
                                    type="checkbox"
                                    name="has_quota"
                                    value="1"
                                    checked={hasQuota}
                                    onChange={(event) =>
                                        setHasQuota(event.target.checked)
                                    }
                                    className="size-4 accent-primary"
                                />
                                Berjatah (punya jatah hari per tahun). Tidak
                                bisa diubah sesudah disimpan.
                            </label>
                        )}
                        {hasQuota && (
                            <div className="grid content-start gap-1.5">
                                <Label htmlFor={`${prefix}-quota`}>
                                    Jatah per tahun (hari)
                                </Label>
                                <Input
                                    id={`${prefix}-quota`}
                                    name="annual_quota"
                                    type="number"
                                    min={1}
                                    max={maxDays}
                                    defaultValue={type?.annual_quota ?? 12}
                                    required
                                />
                                <InputError message={errors.annual_quota} />
                            </div>
                        )}
                        <div className="grid content-start gap-1.5">
                            <Label htmlFor={`${prefix}-max`}>
                                Batas hari per pengajuan (opsional)
                            </Label>
                            <Input
                                id={`${prefix}-max`}
                                name="max_days"
                                type="number"
                                min={1}
                                max={maxDays}
                                defaultValue={type?.max_days ?? ''}
                            />
                            <InputError message={errors.max_days} />
                        </div>
                    </div>
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
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

// ── Libur mingguan ───────────────────────────────────────────────────────────

type RulesProp = Props['weeklyOff'];

/**
 * Pilihan sasaran aturan berjenjang: satu jenjang, atau satu lembaga/unit.
 */
function TargetFields({
    idPrefix,
    options,
    errors,
}: {
    idPrefix: string;
    options: Props['options'];
    errors: Record<string, string | undefined>;
}) {
    const [target, setTarget] = useState<'jenjang' | 'lembaga'>('jenjang');

    return (
        <>
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
            {target === 'jenjang' ? (
                <div className="grid gap-1.5">
                    <Label htmlFor={`${idPrefix}-jenjang`}>Jenjang</Label>
                    <select
                        id={`${idPrefix}-jenjang`}
                        name="jenjang"
                        className={selectClass}
                        defaultValue=""
                        required
                    >
                        <option value="" disabled>
                            Pilih jenjang
                        </option>
                        {options.jenjang.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.jenjang} />
                </div>
            ) : (
                <div className="grid gap-1.5">
                    <Label htmlFor={`${idPrefix}-organization`}>
                        Lembaga / unit
                    </Label>
                    <select
                        id={`${idPrefix}-organization`}
                        name="organization_id"
                        className={selectClass}
                        defaultValue=""
                        required
                    >
                        <option value="" disabled>
                            Pilih lembaga atau unit
                        </option>
                        {options.organizations.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.organization_id} />
                </div>
            )}
        </>
    );
}

/**
 * Daftar aturan yang tersimpan + nilai yang berlaku di tiap lembaga.
 */
function RulesPanel({
    data,
    emptyText,
    usageTitle,
}: {
    data: RulesProp;
    emptyText: string;
    usageTitle: string;
}) {
    return (
        <div className="space-y-4">
            <div className="rounded-xl border">
                {data.rules.length === 0 ? (
                    <p className="p-4 text-sm text-muted-foreground">
                        {emptyText}
                    </p>
                ) : (
                    <ul className="divide-y">
                        {data.rules.map((rule) => (
                            <li
                                key={rule.id}
                                className="flex flex-wrap items-center justify-between gap-2 p-4 text-sm"
                            >
                                <span className="font-medium break-words">
                                    {rule.target}
                                </span>
                                <span className="flex flex-wrap items-center gap-1.5 text-muted-foreground">
                                    {rule.days}
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
            {data.usage.length > 0 && (
                <details className="rounded-xl border">
                    <summary className="cursor-pointer p-4 text-sm font-medium">
                        {usageTitle} ({data.usage.length})
                    </summary>
                    <ul className="divide-y border-t">
                        {data.usage.map((row) => (
                            <li
                                key={row.id}
                                className="flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-sm"
                            >
                                <span className="break-words">{row.name}</span>
                                <span className="flex items-center gap-1.5">
                                    {row.days}
                                    <Badge variant="outline">
                                        {row.source}
                                    </Badge>
                                </span>
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </div>
    );
}

function EnforcedCheckbox({ text }: { text: string }) {
    return (
        <label className="flex cursor-pointer items-start gap-2 text-sm">
            <input
                type="checkbox"
                name="enforced"
                value="1"
                className="mt-0.5 size-4 accent-primary"
            />
            {text}
        </label>
    );
}

function WeeklyOffSection({ weeklyOff, dayOptions, options }: Props) {
    return (
        <section aria-label="Libur mingguan" className="space-y-3">
            <Heading
                variant="small"
                title="Libur mingguan"
                description="Hari yang tidak dihitung sebagai hari cuti, per jenjang atau lembaga/unit. Bawaan: Ahad. Lembaga pengajuan = penempatan utama pegawai."
            />
            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <Form
                    action={`${BASE_URL}/libur-mingguan`}
                    method="post"
                    errorBag="weeklyOff"
                    options={{ preserveScroll: true }}
                    className="space-y-3 rounded-xl border p-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <TargetFields
                                idPrefix="wo"
                                options={options}
                                errors={errors}
                            />
                            <fieldset className="space-y-1.5">
                                <legend className="text-sm font-medium">
                                    Hari libur
                                </legend>
                                <div className="grid grid-cols-[repeat(auto-fit,minmax(7rem,1fr))] gap-2">
                                    {dayOptions.map((option) => (
                                        <label
                                            key={option.value}
                                            className="flex cursor-pointer items-center gap-2 rounded-md border p-2 text-sm hover:bg-muted"
                                        >
                                            <input
                                                type="checkbox"
                                                name="weekly_off_days[]"
                                                value={option.value}
                                                defaultChecked={
                                                    option.value === '7'
                                                }
                                                className="size-4 accent-primary"
                                            />
                                            {option.label}
                                        </label>
                                    ))}
                                </div>
                                <InputError
                                    message={
                                        errors.weekly_off_days ??
                                        errors['weekly_off_days.0'] ??
                                        errors.form
                                    }
                                />
                            </fieldset>
                            <EnforcedCheckbox text="Kunci: lembaga di bawahnya tidak bisa memakai libur mingguan lain." />
                            <Button type="submit" disabled={processing}>
                                Simpan libur mingguan
                            </Button>
                        </>
                    )}
                </Form>

                <RulesPanel
                    data={weeklyOff}
                    emptyText="Belum ada pengaturan. Semua lembaga libur hari Ahad."
                    usageTitle="Libur mingguan yang berlaku di tiap lembaga"
                />
            </div>
        </section>
    );
}

// ── Alur persetujuan (HR-3c) ─────────────────────────────────────────────────

function ApprovalSection({ approval, options }: Props) {
    return (
        <section aria-label="Alur persetujuan" className="space-y-3">
            <Heading
                variant="small"
                title="Alur persetujuan"
                description="2 tahap: Kepala Lembaga lalu Admin SDM (bawaan). 1 tahap: cukup Kepala Lembaga. Berlaku untuk pengajuan baru; pengajuan yang sudah masuk tetap memakai alur saat diajukan."
            />
            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <Form
                    action={`${BASE_URL}/alur-persetujuan`}
                    method="post"
                    errorBag="approval"
                    options={{ preserveScroll: true }}
                    className="space-y-3 rounded-xl border p-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <TargetFields
                                idPrefix="ap"
                                options={options}
                                errors={errors}
                            />
                            <div className="grid gap-1.5">
                                <Label htmlFor="ap-steps">Alur</Label>
                                <select
                                    id="ap-steps"
                                    name="steps"
                                    className={selectClass}
                                    defaultValue="2"
                                    required
                                >
                                    <option value="2">
                                        2 tahap: Kepala Lembaga → Admin SDM
                                    </option>
                                    <option value="1">
                                        1 tahap: Kepala Lembaga saja
                                    </option>
                                </select>
                                <InputError
                                    message={errors.steps ?? errors.form}
                                />
                            </div>
                            <EnforcedCheckbox text="Kunci: lembaga di bawahnya tidak bisa memakai alur lain." />
                            <Button type="submit" disabled={processing}>
                                Simpan alur persetujuan
                            </Button>
                        </>
                    )}
                </Form>

                <RulesPanel
                    data={approval}
                    emptyText="Belum ada pengaturan. Semua lembaga memakai alur 2 tahap."
                    usageTitle="Alur yang berlaku di tiap lembaga"
                />
            </div>
        </section>
    );
}

// ── Hari libur ───────────────────────────────────────────────────────────────

function HolidaysSection({
    holidays,
    year,
}: {
    holidays: Holiday[];
    year: number;
}) {
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<string | null>(null);
    const [removing, setRemoving] = useState<string | null>(null);

    return (
        <section aria-label="Hari libur" className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <Heading
                    variant="small"
                    title="Hari libur (tanggal merah)"
                    description="Berlaku untuk seluruh yayasan; tidak dihitung sebagai hari cuti."
                />
                <div className="grid w-full grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex sm:w-auto">
                    <select
                        aria-label="Tahun"
                        className={selectClass + ' sm:w-28'}
                        value={year}
                        onChange={(event) =>
                            router.get(
                                `${BASE_URL}/pengaturan`,
                                { tahun: event.target.value },
                                { preserveScroll: true },
                            )
                        }
                    >
                        {[year - 1, year, year + 1].map((option) => (
                            <option key={option} value={option}>
                                {option}
                            </option>
                        ))}
                    </select>
                    <Button
                        type="button"
                        variant={adding ? 'default' : 'outline'}
                        onClick={() => setAdding((current) => !current)}
                    >
                        <Plus />
                        Tambah hari libur
                    </Button>
                </div>
            </div>

            {adding && (
                <HolidayForm year={year} onDone={() => setAdding(false)} />
            )}

            <ul className="divide-y rounded-xl border">
                {holidays.length === 0 && (
                    <li className="p-4 text-sm text-muted-foreground">
                        Belum ada hari libur di tahun {year}.
                    </li>
                )}
                {holidays.map((holiday) => (
                    <li key={holiday.id} className="space-y-3 p-4">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div className="min-w-0 text-sm">
                                <p className="font-medium break-words">
                                    {holiday.name}
                                </p>
                                <p className="text-muted-foreground">
                                    {holiday.start_date === holiday.end_date
                                        ? formatDate(holiday.start_date)
                                        : `${formatDate(holiday.start_date)} – ${formatDate(holiday.end_date)}`}{' '}
                                    · {holiday.days} hari
                                </p>
                            </div>
                            <div className="grid w-full grid-cols-[repeat(auto-fit,minmax(7rem,1fr))] gap-2 sm:flex sm:w-auto">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        setEditing((current) =>
                                            current === holiday.id
                                                ? null
                                                : holiday.id,
                                        )
                                    }
                                >
                                    <Pencil />
                                    Ubah
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        setRemoving((current) =>
                                            current === holiday.id
                                                ? null
                                                : holiday.id,
                                        )
                                    }
                                >
                                    <Trash2 />
                                    Hapus
                                </Button>
                            </div>
                        </div>
                        {editing === holiday.id && (
                            <HolidayForm
                                year={year}
                                holiday={holiday}
                                onDone={() => setEditing(null)}
                            />
                        )}
                        {removing === holiday.id && (
                            <Form
                                action={`${BASE_URL}/hari-libur/${holiday.id}`}
                                method="delete"
                                errorBag={`hr-holiday-remove-${holiday.id}`}
                                options={{ preserveScroll: true }}
                                onSuccess={() => setRemoving(null)}
                                className="space-y-2 rounded-md border border-red-500/40 p-3 text-sm"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <p>
                                            Hapus hari libur{' '}
                                            <strong>{holiday.name}</strong>?
                                        </p>
                                        <InputError message={errors.form} />
                                        <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
                                            <Button
                                                type="submit"
                                                variant="destructive"
                                                disabled={processing}
                                            >
                                                Hapus hari libur
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() =>
                                                    setRemoving(null)
                                                }
                                            >
                                                Batal
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}

function HolidayForm({
    year,
    holiday,
    onDone,
}: {
    year: number;
    holiday?: Holiday;
    onDone: () => void;
}) {
    const [start, setStart] = useState(holiday?.start_date ?? '');
    const [end, setEnd] = useState(holiday?.end_date ?? '');
    const prefix = holiday ? `hh-${holiday.id}` : 'hh-new';

    return (
        <Form
            action={
                holiday
                    ? `${BASE_URL}/hari-libur/${holiday.id}`
                    : `${BASE_URL}/hari-libur`
            }
            method={holiday ? 'patch' : 'post'}
            errorBag={holiday ? `hr-holiday-${holiday.id}` : 'hr-holiday-new'}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-3 rounded-xl border border-primary/30 bg-muted/20 p-4"
        >
            {({ processing, errors }) => (
                <>
                    <InputError message={errors.form} />
                    <div className="grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)]">
                        <div className="grid content-start gap-1.5">
                            <Label htmlFor={`${prefix}-name`}>Nama</Label>
                            <Input
                                id={`${prefix}-name`}
                                name="name"
                                defaultValue={holiday?.name ?? ''}
                                maxLength={100}
                                placeholder="mis. Idulfitri"
                                required
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid content-start gap-1.5">
                            <Label htmlFor={`${prefix}-start`}>
                                Tanggal mulai
                            </Label>
                            <Input
                                id={`${prefix}-start`}
                                type="date"
                                name="start_date"
                                value={start}
                                min={`${year - 1}-01-01`}
                                onChange={(event) => {
                                    setStart(event.target.value);

                                    // Libur satu hari: tanggal selesai ikut terisi.
                                    if (
                                        end === '' ||
                                        end < event.target.value
                                    ) {
                                        setEnd(event.target.value);
                                    }
                                }}
                                required
                            />
                            <InputError message={errors.start_date} />
                        </div>
                        <div className="grid content-start gap-1.5">
                            <Label htmlFor={`${prefix}-end`}>
                                Tanggal selesai
                            </Label>
                            <Input
                                id={`${prefix}-end`}
                                type="date"
                                name="end_date"
                                value={end}
                                min={start || undefined}
                                onChange={(event) => setEnd(event.target.value)}
                                required
                            />
                            <InputError message={errors.end_date} />
                        </div>
                    </div>
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
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

// ── Jam kerja (HR-4a) ────────────────────────────────────────────────────────

function WorkHoursSection({ workHours, options }: Props) {
    return (
        <section aria-label="Jam kerja" className="space-y-3">
            <Heading
                variant="small"
                title="Jam kerja (presensi)"
                description="Jam masuk & pulang staf pada hari kerja, dan toleransi terlambat. Guru memakai jam pelajaran pertama–terakhir dari jadwal mengajar, dengan toleransi yang sama. Bawaan 07.00–14.00, toleransi 15 menit."
            />
            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <Form
                    action={`${BASE_URL}/jam-kerja`}
                    method="post"
                    errorBag="workHours"
                    options={{ preserveScroll: true }}
                    className="space-y-3 rounded-xl border p-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <TargetFields
                                idPrefix="wh"
                                options={options}
                                errors={errors}
                            />
                            <div className="grid grid-cols-[repeat(auto-fit,minmax(8rem,1fr))] gap-3">
                                <div className="grid content-start gap-1.5">
                                    <Label htmlFor="wh-start">Jam masuk</Label>
                                    <Input
                                        id="wh-start"
                                        type="time"
                                        name="start"
                                        defaultValue="07:00"
                                        required
                                    />
                                    <InputError message={errors.start} />
                                </div>
                                <div className="grid content-start gap-1.5">
                                    <Label htmlFor="wh-end">Jam pulang</Label>
                                    <Input
                                        id="wh-end"
                                        type="time"
                                        name="end"
                                        defaultValue="14:00"
                                        required
                                    />
                                    <InputError message={errors.end} />
                                </div>
                                <div className="grid content-start gap-1.5">
                                    <Label htmlFor="wh-tolerance">
                                        Toleransi (menit)
                                    </Label>
                                    <Input
                                        id="wh-tolerance"
                                        type="number"
                                        name="tolerance"
                                        min={0}
                                        max={120}
                                        defaultValue={15}
                                        required
                                    />
                                    <InputError message={errors.tolerance} />
                                </div>
                            </div>
                            <InputError message={errors.form} />
                            <EnforcedCheckbox text="Kunci: lembaga di bawahnya tidak bisa memakai jam kerja lain." />
                            <Button type="submit" disabled={processing}>
                                Simpan jam kerja
                            </Button>
                        </>
                    )}
                </Form>

                <RulesPanel
                    data={workHours}
                    emptyText="Belum ada pengaturan. Semua lembaga memakai 07.00–14.00, toleransi 15 menit."
                    usageTitle="Jam kerja yang berlaku di tiap lembaga"
                />
            </div>
        </section>
    );
}
