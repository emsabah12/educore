import { Form, Head, router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { RulesData, TargetOptions } from '@/components/core/scoped-rules';
import {
    EnforcedCheckbox,
    RulesPanel,
    TargetFields,
} from '@/components/core/scoped-rules';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type FeeTypeRow = {
    id: string;
    code: string;
    name: string;
    kind: 'BULANAN' | 'SEKALI';
    kind_label: string;
    is_active: boolean;
    /** Jumlah tarif yang sudah diisi (semua lembaga & tahun ajaran). */
    rates: number;
};

type RateRow = {
    type_id: string;
    code: string;
    name: string;
    kind_label: string;
    is_active: boolean;
    all: { id: string; amount: number } | null;
    grades: { id: string; grade: number; amount: number }[];
};

type Props = {
    types: FeeTypeRow[];
    kinds: Option[];
    organizations: Option[];
    organizationId: string | null;
    years: Option[];
    yearId: string | null;
    rates: RateRow[];
    dueDay: RulesData;
    approvalLimit: RulesData;
    targets: TargetOptions;
};

const rupiah = new Intl.NumberFormat('id-ID');

const TYPES_URL = '/keuangan/jenis-biaya';

const selectClass =
    'h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

/**
 * Pengaturan keuangan (PRD-003 §6, FN-1a): jenis biaya yayasan dan tarifnya per lembaga × tahun ajaran
 * (opsional per tingkat). Hanya Admin Keuangan / Admin Yayasan; server tetap memeriksa ulang.
 */
export default function FinanceSettings({
    types,
    kinds,
    organizations,
    organizationId,
    years,
    yearId,
    rates,
    dueDay,
    approvalLimit,
    targets,
}: Props) {
    return (
        <>
            <Head title="Pengaturan keuangan" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Pengaturan keuangan"
                    description="Jenis biaya siswa dan tarifnya per lembaga & tahun ajaran. Tagihan dibuat dari tarif ini; mengubah tarif tidak mengubah tagihan yang sudah dibuat."
                />

                <div className="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <section
                        aria-label="Daftar jenis biaya"
                        className="rounded-xl border"
                    >
                        <h2 className="border-b px-4 py-3 text-base font-medium">
                            Jenis biaya
                        </h2>
                        {types.length === 0 ? (
                            <p className="p-4 text-sm text-muted-foreground">
                                Belum ada jenis biaya. Tambahkan yang pertama,
                                mis. SPP (bulanan) atau Daftar ulang (sekali
                                bayar).
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {types.map((type) => (
                                    <TypeRow key={type.id} type={type} />
                                ))}
                            </ul>
                        )}
                    </section>

                    <section
                        aria-label="Tambah jenis biaya"
                        className="h-fit rounded-xl border p-4"
                    >
                        <CreateTypeForm kinds={kinds} />
                    </section>
                </div>

                <RatesSection
                    organizations={organizations}
                    organizationId={organizationId}
                    years={years}
                    yearId={yearId}
                    rates={rates}
                />

                <DueDaySection data={dueDay} targets={targets} />

                <ApprovalLimitSection data={approvalLimit} targets={targets} />
            </div>
        </>
    );
}

/**
 * Jatuh tempo tagihan bulanan (FN-2a): tanggal 1–28 di bulan tagihan, se-yayasan, per jenjang, atau per
 * lembaga/unit (aturan berjenjang). Berlaku untuk tagihan yang dibuat sesudahnya.
 */
function DueDaySection({
    data,
    targets,
}: {
    data: RulesData;
    targets: TargetOptions;
}) {
    return (
        <section aria-label="Jatuh tempo" className="space-y-3">
            <Heading
                variant="small"
                title="Jatuh tempo tagihan bulanan"
                description="Tanggal jatuh tempo SPP dan tagihan bulanan lain di bulan tagihannya. Bawaan tanggal 10. Tagihan yang sudah dibuat tidak berubah."
            />
            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <Form
                    action="/keuangan/jatuh-tempo"
                    method="post"
                    errorBag="dueDay"
                    options={{ preserveScroll: true }}
                    className="space-y-3 rounded-xl border p-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <TargetFields
                                idPrefix="due"
                                options={targets}
                                errors={errors}
                            />
                            <div className="grid gap-1.5 sm:max-w-48">
                                <Label htmlFor="due-day">Tanggal (1–28)</Label>
                                <Input
                                    id="due-day"
                                    name="day"
                                    type="number"
                                    min={1}
                                    max={28}
                                    defaultValue={10}
                                    required
                                />
                                <InputError message={errors.day} />
                            </div>
                            <InputError message={errors.form} />
                            <EnforcedCheckbox text="Kunci: lembaga di bawahnya tidak bisa memakai tanggal lain." />
                            <Button type="submit" disabled={processing}>
                                Simpan jatuh tempo
                            </Button>
                        </>
                    )}
                </Form>
                <RulesPanel
                    data={data}
                    emptyText="Belum ada pengaturan. Semua lembaga: tanggal 10 setiap bulan."
                    usageTitle="Jatuh tempo yang berlaku di tiap lembaga"
                />
            </div>
        </section>
    );
}

/**
 * Batas persetujuan pengeluaran berjenjang (PRD-004 KS-5): jumlah disetujui Kepala Lembaga di atas batas perlu
 * persetujuan kedua tingkat Yayasan. 0 = tanpa batas. Aturan berjenjang seperti jatuh tempo.
 */
function ApprovalLimitSection({
    data,
    targets,
}: {
    data: RulesData;
    targets: TargetOptions;
}) {
    return (
        <section
            aria-label="Batas persetujuan pengeluaran"
            className="space-y-3"
        >
            <Heading
                variant="small"
                title="Batas persetujuan pengeluaran"
                description="Pengeluaran kas lembaga yang disetujui Kepala Lembaga di atas batas ini perlu persetujuan kedua dari Pimpinan/Admin Keuangan Yayasan. Isi 0 untuk tanpa batas (bawaan)."
            />
            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <Form
                    action="/keuangan/batas-persetujuan"
                    method="post"
                    errorBag="approvalLimit"
                    options={{ preserveScroll: true }}
                    className="space-y-3 rounded-xl border p-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <TargetFields
                                idPrefix="limit"
                                options={targets}
                                errors={errors}
                            />
                            <div className="grid gap-1.5 sm:max-w-64">
                                <Label htmlFor="limit-value">Batas (Rp)</Label>
                                <Input
                                    id="limit-value"
                                    name="limit"
                                    inputMode="numeric"
                                    placeholder="mis. 5.000.000"
                                    required
                                />
                                <InputError message={errors.limit} />
                            </div>
                            <InputError message={errors.form} />
                            <EnforcedCheckbox text="Kunci: lembaga di bawahnya tidak bisa memakai batas lain." />
                            <Button type="submit" disabled={processing}>
                                Simpan batas
                            </Button>
                        </>
                    )}
                </Form>
                <RulesPanel
                    data={data}
                    emptyText="Belum ada pengaturan. Semua lembaga: tanpa batas (cukup Kepala Lembaga)."
                    usageTitle="Batas yang berlaku di tiap lembaga"
                />
            </div>
        </section>
    );
}

function CreateTypeForm({ kinds }: { kinds: Option[] }) {
    return (
        <Form
            action={TYPES_URL}
            method="post"
            errorBag="createType"
            resetOnSuccess
            options={{ preserveScroll: true }}
            className="space-y-3"
        >
            {({ processing, errors }) => (
                <>
                    <h3 className="text-sm font-medium">Tambah jenis biaya</h3>

                    <div className="grid gap-1.5">
                        <Label htmlFor="fee-code">Kode</Label>
                        <Input
                            id="fee-code"
                            name="code"
                            placeholder="mis. SPP"
                            maxLength={30}
                            className="uppercase"
                            required
                        />
                        <p className="text-xs text-muted-foreground">
                            Huruf, angka, dan tanda “-”. Kode tidak bisa diubah
                            setelah disimpan.
                        </p>
                        <InputError message={errors.code} />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="fee-name">Nama</Label>
                        <Input
                            id="fee-name"
                            name="name"
                            placeholder="mis. SPP bulanan"
                            maxLength={100}
                            required
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="fee-kind">Sifat</Label>
                        <select
                            id="fee-kind"
                            name="kind"
                            className={selectClass}
                            defaultValue="BULANAN"
                        >
                            {kinds.map((kind) => (
                                <option key={kind.value} value={kind.value}>
                                    {kind.label}
                                </option>
                            ))}
                        </select>
                        <p className="text-xs text-muted-foreground">
                            Bulanan = ditagih tiap bulan tahun ajaran (mis.
                            SPP). Sekali bayar = ditagih sekali (mis. daftar
                            ulang, seragam). Sifat tidak bisa diubah setelah
                            disimpan.
                        </p>
                        <InputError message={errors.kind} />
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

function TypeRow({ type }: { type: FeeTypeRow }) {
    const [editing, setEditing] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const itemUrl = `${TYPES_URL}/${type.id}`;

    return (
        <li className="space-y-3 p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0 space-y-1">
                    <p
                        className={
                            'font-medium break-words ' +
                            (type.is_active ? '' : 'text-muted-foreground')
                        }
                    >
                        {type.name}
                    </p>
                    <div className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                        <span className="font-mono">{type.code}</span>
                        <Badge variant="secondary">{type.kind_label}</Badge>
                        {!type.is_active && (
                            <Badge variant="outline">Nonaktif</Badge>
                        )}
                        <span>{type.rates} tarif</span>
                    </div>
                </div>

                {!editing && !confirming && (
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(8rem,1fr))] gap-2 sm:flex">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setEditing(true)}
                        >
                            <Pencil />
                            Ubah nama
                        </Button>
                        {type.is_active ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setConfirming(true)}
                            >
                                Nonaktifkan
                            </Button>
                        ) : (
                            <PostButton
                                action={`${itemUrl}/aktifkan`}
                                errorBag={`status-${type.id}`}
                            >
                                Aktifkan kembali
                            </PostButton>
                        )}
                    </div>
                )}
            </div>

            {editing && (
                <Form
                    action={itemUrl}
                    method="patch"
                    errorBag={`rename-${type.id}`}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setEditing(false)}
                    className="space-y-2"
                >
                    {({ processing, errors }) => (
                        <>
                            <Label
                                htmlFor={`rename-${type.id}`}
                                className="sr-only"
                            >
                                Nama baru
                            </Label>
                            <div className="flex flex-col gap-2 sm:flex-row">
                                <Input
                                    id={`rename-${type.id}`}
                                    name="name"
                                    defaultValue={type.name}
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
            )}

            {confirming && (
                <div className="space-y-2 rounded-md border border-destructive/40 p-3">
                    <p className="text-sm">
                        Nonaktifkan {type.name}? Jenis biaya ini tidak bisa
                        diberi tarif baru dan tidak ditagihkan lagi. Tarif &
                        tagihan yang sudah ada tetap tersimpan.
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <PostButton
                            action={`${itemUrl}/nonaktifkan`}
                            errorBag={`status-${type.id}`}
                            variant="destructive"
                            onSuccess={() => setConfirming(false)}
                        >
                            Ya, nonaktifkan
                        </PostButton>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setConfirming(false)}
                        >
                            Batal
                        </Button>
                    </div>
                </div>
            )}
        </li>
    );
}

/**
 * Tarif per lembaga × tahun ajaran. "Semua tingkat" berlaku untuk seluruh tingkat; tarif per tingkat
 * didahulukan (OD-FN-04). Menyimpan kombinasi yang sudah ada = mengubah nominalnya.
 */
function RatesSection({
    organizations,
    organizationId,
    years,
    yearId,
    rates,
}: {
    organizations: Option[];
    organizationId: string | null;
    years: Option[];
    yearId: string | null;
    rates: RateRow[];
}) {
    const visit = (params: Record<string, string>) =>
        router.get('/keuangan/pengaturan', params, {
            preserveScroll: true,
            preserveState: true,
        });

    return (
        <section aria-label="Tarif" className="space-y-4 rounded-xl border p-4">
            <div className="space-y-1">
                <h2 className="text-base font-medium">Tarif</h2>
                <p className="text-sm text-muted-foreground">
                    Pilih lembaga dan tahun ajaran (dari kalender akademik
                    lembaga), lalu isi nominal. Tarif per tingkat (mis. kelas 9)
                    didahulukan daripada tarif semua tingkat.
                </p>
            </div>

            {organizations.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Belum ada lembaga aktif di yayasan ini.
                </p>
            ) : (
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="grid gap-1.5">
                        <Label htmlFor="rate-organization">Lembaga</Label>
                        <select
                            id="rate-organization"
                            className={selectClass}
                            value={organizationId ?? ''}
                            onChange={(event) =>
                                visit({ lembaga: event.target.value })
                            }
                        >
                            {organizations.map((organization) => (
                                <option
                                    key={organization.value}
                                    value={organization.value}
                                >
                                    {organization.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="rate-year">Tahun ajaran</Label>
                        <select
                            id="rate-year"
                            className={selectClass}
                            value={yearId ?? ''}
                            disabled={years.length === 0}
                            onChange={(event) =>
                                visit({
                                    lembaga: organizationId ?? '',
                                    tahun: event.target.value,
                                })
                            }
                        >
                            {years.map((year) => (
                                <option key={year.value} value={year.value}>
                                    {year.label}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>
            )}

            {organizationId !== null && years.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    Kalender akademik lembaga ini belum punya tahun ajaran.
                    Tambahkan dulu di menu Kalender akademik.
                </p>
            )}

            {organizationId !== null &&
                yearId !== null &&
                (rates.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Belum ada jenis biaya aktif. Tambahkan jenis biaya di
                        atas terlebih dulu.
                    </p>
                ) : (
                    <ul className="divide-y rounded-lg border">
                        {rates.map((row) => (
                            <RateRowEditor
                                key={`${row.type_id}-${organizationId}-${yearId}`}
                                row={row}
                                organizationId={organizationId}
                                yearId={yearId}
                            />
                        ))}
                    </ul>
                ))}
        </section>
    );
}

function RateRowEditor({
    row,
    organizationId,
    yearId,
}: {
    row: RateRow;
    organizationId: string;
    yearId: string;
}) {
    const [addingGrade, setAddingGrade] = useState(false);
    const action = `${TYPES_URL}/${row.type_id}/tarif`;

    return (
        <li className="space-y-3 p-4">
            <div className="flex flex-wrap items-center gap-1.5">
                <span className="font-medium break-words">{row.name}</span>
                <Badge variant="secondary">{row.kind_label}</Badge>
                {!row.is_active && <Badge variant="outline">Nonaktif</Badge>}
            </div>

            {!row.is_active ? (
                <p className="text-sm text-muted-foreground">
                    Jenis biaya nonaktif: tarif di bawah tetap tersimpan, tetapi
                    tidak bisa diubah.
                </p>
            ) : (
                <AmountForm
                    action={action}
                    errorBag={`rate-${row.type_id}-all`}
                    organizationId={organizationId}
                    yearId={yearId}
                    grade={null}
                    label="Semua tingkat"
                    current={row.all?.amount ?? null}
                    removeId={row.all?.id ?? null}
                />
            )}

            {row.grades.map((rate) => (
                <AmountForm
                    key={rate.id}
                    action={action}
                    errorBag={`rate-${rate.id}`}
                    organizationId={organizationId}
                    yearId={yearId}
                    grade={rate.grade}
                    label={`Tingkat ${rate.grade}`}
                    current={rate.amount}
                    removeId={rate.id}
                    readOnly={!row.is_active}
                />
            ))}

            {row.is_active &&
                (addingGrade ? (
                    <AddGradeForm
                        action={action}
                        errorBag={`rate-${row.type_id}-grade`}
                        organizationId={organizationId}
                        yearId={yearId}
                        onDone={() => setAddingGrade(false)}
                    />
                ) : (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => setAddingGrade(true)}
                    >
                        <Plus />
                        Tarif khusus tingkat
                    </Button>
                ))}
        </li>
    );
}

function AmountForm({
    action,
    errorBag,
    organizationId,
    yearId,
    grade,
    label,
    current,
    removeId,
    readOnly = false,
}: {
    action: string;
    errorBag: string;
    organizationId: string;
    yearId: string;
    grade: number | null;
    label: string;
    current: number | null;
    removeId: string | null;
    readOnly?: boolean;
}) {
    const inputId = `${errorBag}-amount`;

    if (readOnly) {
        return (
            <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                <span className="text-muted-foreground">{label}</span>
                <span className="tabular-nums">
                    Rp {current === null ? '–' : rupiah.format(current)}
                </span>
            </div>
        );
    }

    return (
        <div className="space-y-1">
            <Form
                action={action}
                method="post"
                errorBag={errorBag}
                options={{ preserveScroll: true }}
                className="grid grid-cols-[minmax(6rem,auto)_minmax(0,1fr)_auto] items-center gap-2"
            >
                {({ processing, errors }) => (
                    <>
                        <Label
                            htmlFor={inputId}
                            className="text-sm font-normal text-muted-foreground"
                        >
                            {label}
                        </Label>
                        <input
                            type="hidden"
                            name="organization_id"
                            value={organizationId}
                        />
                        <input
                            type="hidden"
                            name="academic_year_id"
                            value={yearId}
                        />
                        {grade !== null && (
                            <input type="hidden" name="grade" value={grade} />
                        )}
                        <Input
                            id={inputId}
                            name="amount"
                            inputMode="numeric"
                            placeholder="Belum diisi"
                            defaultValue={
                                current === null ? '' : rupiah.format(current)
                            }
                            maxLength={20}
                            required
                            className="tabular-nums"
                        />
                        <Button
                            type="submit"
                            size="sm"
                            variant="outline"
                            disabled={processing}
                        >
                            Simpan
                        </Button>
                        <div className="col-span-3">
                            <InputError
                                message={
                                    errors.amount ??
                                    errors.grade ??
                                    errors.academic_year_id ??
                                    errors.organization_id ??
                                    errors.form
                                }
                            />
                        </div>
                    </>
                )}
            </Form>
            {removeId !== null && (
                <RemoveRateButton rateId={removeId} label={label} />
            )}
        </div>
    );
}

function AddGradeForm({
    action,
    errorBag,
    organizationId,
    yearId,
    onDone,
}: {
    action: string;
    errorBag: string;
    organizationId: string;
    yearId: string;
    onDone: () => void;
}) {
    return (
        <Form
            action={action}
            method="post"
            errorBag={errorBag}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-2 rounded-md border border-primary/30 bg-muted/20 p-3"
        >
            {({ processing, errors }) => (
                <>
                    <input
                        type="hidden"
                        name="organization_id"
                        value={organizationId}
                    />
                    <input
                        type="hidden"
                        name="academic_year_id"
                        value={yearId}
                    />
                    <div className="grid gap-2 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor={`${errorBag}-grade`}>Tingkat</Label>
                            <Input
                                id={`${errorBag}-grade`}
                                name="grade"
                                type="number"
                                min={1}
                                max={12}
                                required
                            />
                            <InputError message={errors.grade} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor={`${errorBag}-amount`}>
                                Nominal (Rp)
                            </Label>
                            <Input
                                id={`${errorBag}-amount`}
                                name="amount"
                                inputMode="numeric"
                                maxLength={20}
                                required
                            />
                            <InputError
                                message={errors.amount ?? errors.form}
                            />
                        </div>
                    </div>
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(8rem,1fr))] gap-2 sm:flex">
                        <Button type="submit" size="sm" disabled={processing}>
                            Simpan
                        </Button>
                        <Button
                            type="button"
                            size="sm"
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

function RemoveRateButton({
    rateId,
    label,
}: {
    rateId: string;
    label: string;
}) {
    return (
        <Form
            action={`/keuangan/tarif/${rateId}`}
            method="delete"
            errorBag={`remove-${rateId}`}
            options={{ preserveScroll: true }}
        >
            {({ processing, errors }) => (
                <>
                    <Button
                        type="submit"
                        size="sm"
                        variant="ghost"
                        disabled={processing}
                        aria-label={`Hapus tarif ${label}`}
                        className="text-destructive"
                    >
                        <Trash2 />
                        Hapus tarif {label.toLowerCase()}
                    </Button>
                    <InputError message={errors.form} />
                </>
            )}
        </Form>
    );
}

function PostButton({
    action,
    errorBag,
    variant = 'outline',
    onSuccess,
    children,
}: {
    action: string;
    errorBag: string;
    variant?: 'outline' | 'destructive';
    onSuccess?: () => void;
    children: React.ReactNode;
}) {
    return (
        <Form
            action={action}
            method="post"
            errorBag={errorBag}
            options={{ preserveScroll: true }}
            onSuccess={onSuccess}
        >
            {({ processing, errors }) => (
                <>
                    <Button
                        type="submit"
                        variant={variant}
                        size="sm"
                        disabled={processing}
                    >
                        {children}
                    </Button>
                    <InputError message={errors.form} />
                </>
            )}
        </Form>
    );
}
