import { Form, Head, router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { FormDialog } from '@/components/form-dialog';
import type { RulesData, TargetOptions } from '@/components/core/scoped-rules';
import {
    EnforcedCheckbox,
    RuleDialog,
    RulesPanel,
    TargetFields,
} from '@/components/core/scoped-rules';
import Heading from '@/components/heading';
import { PageTabs, usePageTab } from '@/components/page-tabs';
import { ConfirmAction } from '@/components/confirm-action';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { selectClass } from '@/components/native-select';

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
    onlinePayments: OnlinePayments;
};

type OnlineMethod = {
    method: string;
    label: string;
    enabled: boolean;
    fee_value: number;
    fee_is_percent: boolean;
    fee_input: string;
    fee_label: string;
};

type OnlinePayments = {
    /** Kunci Midtrans terpasang di .env server (kuncinya sendiri tidak pernah dikirim). */
    configured: boolean;
    production: boolean;
    methods: OnlineMethod[];
    maxFixedFee: number;
};

const rupiah = new Intl.NumberFormat('id-ID');

const TYPES_URL = '/keuangan/jenis-biaya';

const TABS = ['jenis', 'tarif', 'jatuh-tempo', 'batas', 'online'] as const;

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
    onlinePayments,
}: Props) {
    const [tab, setTab] = usePageTab(TABS, 'jenis');
    const [creating, setCreating] = useState(false);

    return (
        <>
            <Head title="Pengaturan keuangan" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Pengaturan keuangan"
                    description="Jenis biaya siswa, tarif per lembaga & tahun ajaran, jatuh tempo, batas persetujuan pengeluaran, dan pembayaran online."
                />

                <PageTabs
                    label="Bagian pengaturan keuangan"
                    tabs={[
                        { value: 'jenis', label: 'Jenis biaya' },
                        { value: 'tarif', label: 'Tarif' },
                        { value: 'jatuh-tempo', label: 'Jatuh tempo' },
                        { value: 'batas', label: 'Batas persetujuan' },
                        { value: 'online', label: 'Pembayaran online' },
                    ]}
                    value={tab}
                    onChange={setTab}
                />

                {tab === 'jenis' && (
                    <section
                        aria-label="Daftar jenis biaya"
                        className="space-y-3"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <p className="text-sm text-muted-foreground">
                                Tagihan dibuat dari jenis biaya & tarifnya;
                                mengubahnya tidak mengubah tagihan yang sudah
                                dibuat.
                            </p>
                            <Button
                                type="button"
                                onClick={() => setCreating(true)}
                            >
                                <Plus />
                                Tambah jenis biaya
                            </Button>
                        </div>
                        <div className="rounded-xl border">
                            {types.length === 0 ? (
                                <p className="p-4 text-sm text-muted-foreground">
                                    Belum ada jenis biaya. Tambahkan yang
                                    pertama, mis. SPP (bulanan) atau Daftar
                                    ulang (sekali bayar).
                                </p>
                            ) : (
                                <ul className="divide-y">
                                    {types.map((type) => (
                                        <TypeRow key={type.id} type={type} />
                                    ))}
                                </ul>
                            )}
                        </div>
                        <FormDialog
                            open={creating}
                            onOpenChange={setCreating}
                            title="Tambah jenis biaya"
                        >
                            <CreateTypeForm
                                kinds={kinds}
                                onDone={() => setCreating(false)}
                            />
                        </FormDialog>
                    </section>
                )}

                {tab === 'tarif' && (
                    <RatesSection
                        organizations={organizations}
                        organizationId={organizationId}
                        years={years}
                        yearId={yearId}
                        rates={rates}
                    />
                )}

                {tab === 'jatuh-tempo' && (
                    <DueDaySection data={dueDay} targets={targets} />
                )}

                {tab === 'batas' && (
                    <ApprovalLimitSection
                        data={approvalLimit}
                        targets={targets}
                    />
                )}

                {tab === 'online' && (
                    <OnlinePaymentsSection data={onlinePayments} />
                )}
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
            <div className="flex flex-wrap items-start justify-between gap-2">
                <Heading
                    variant="small"
                    title="Jatuh tempo tagihan bulanan"
                    description="Tanggal jatuh tempo SPP dan tagihan bulanan lain di bulan tagihannya. Bawaan tanggal 10. Tagihan yang sudah dibuat tidak berubah."
                />
                <RuleDialog label="Atur jatuh tempo">
                    {(close) => (
                        <Form
                            action="/keuangan/jatuh-tempo"
                            method="post"
                            errorBag="dueDay"
                            options={{ preserveScroll: true }}
                            onSuccess={close}
                            className="space-y-3"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <TargetFields
                                        idPrefix="due"
                                        options={targets}
                                        errors={errors}
                                    />
                                    <div className="grid gap-1.5 sm:max-w-48">
                                        <Label htmlFor="due-day">
                                            Tanggal (1–28)
                                        </Label>
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
                    )}
                </RuleDialog>
            </div>
            <RulesPanel
                data={data}
                emptyText="Belum ada pengaturan. Semua lembaga: tanggal 10 setiap bulan."
                usageTitle="Jatuh tempo yang berlaku di tiap lembaga"
            />
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
            <div className="flex flex-wrap items-start justify-between gap-2">
                <Heading
                    variant="small"
                    title="Batas persetujuan pengeluaran"
                    description="Pengeluaran kas lembaga yang disetujui Kepala Lembaga di atas batas ini perlu persetujuan kedua dari Pimpinan/Admin Keuangan Yayasan. Isi 0 untuk tanpa batas (bawaan)."
                />
                <RuleDialog label="Atur batas persetujuan">
                    {(close) => (
                        <Form
                            action="/keuangan/batas-persetujuan"
                            method="post"
                            errorBag="approvalLimit"
                            options={{ preserveScroll: true }}
                            onSuccess={close}
                            className="space-y-3"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <TargetFields
                                        idPrefix="limit"
                                        options={targets}
                                        errors={errors}
                                    />
                                    <div className="grid gap-1.5 sm:max-w-64">
                                        <Label htmlFor="limit-value">
                                            Batas (Rp)
                                        </Label>
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
                    )}
                </RuleDialog>
            </div>
            <RulesPanel
                data={data}
                emptyText="Belum ada pengaturan. Semua lembaga: tanpa batas (cukup Kepala Lembaga)."
                usageTitle="Batas yang berlaku di tiap lembaga"
            />
        </section>
    );
}

function CreateTypeForm({
    kinds,
    onDone,
}: {
    kinds: Option[];
    onDone: () => void;
}) {
    return (
        <Form
            action={TYPES_URL}
            method="post"
            errorBag="createType"
            resetOnSuccess
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-3"
        >
            {({ processing, errors }) => (
                <>
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
                    <ConfirmAction
                        trigger={
                            <Button type="button" variant="outline" size="sm">
                                {type.is_active
                                    ? 'Nonaktifkan'
                                    : 'Aktifkan kembali'}
                            </Button>
                        }
                        title={
                            type.is_active
                                ? `Nonaktifkan ${type.name}?`
                                : `Aktifkan kembali ${type.name}?`
                        }
                        description={
                            type.is_active
                                ? 'Jenis biaya ini tidak bisa diberi tarif baru dan tidak ditagihkan lagi. Tarif & tagihan yang sudah ada tetap tersimpan.'
                                : 'Jenis biaya ini bisa diberi tarif dan ditagihkan lagi.'
                        }
                        action={`${itemUrl}/${type.is_active ? 'nonaktifkan' : 'aktifkan'}`}
                        confirmLabel={
                            type.is_active
                                ? 'Ya, nonaktifkan'
                                : 'Aktifkan kembali'
                        }
                        destructive={type.is_active}
                        errorBag={`status-${type.id}`}
                    />
                </div>
            </div>

            <FormDialog
                open={editing}
                onOpenChange={setEditing}
                title={`Ubah nama ${type.name}`}
                description="Kode dan jenis tidak bisa diubah."
            >
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
                            <Label htmlFor={`rename-${type.id}`}>
                                Nama baru
                            </Label>
                            <div className="flex flex-col gap-2">
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
            </FormDialog>
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
        router.get(
            '/keuangan/pengaturan',
            { ...params, tab: 'tarif' },
            {
                preserveScroll: true,
                preserveState: true,
            },
        );

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

            {row.is_active && (
                <>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => setAddingGrade(true)}
                    >
                        <Plus />
                        Tarif khusus tingkat
                    </Button>
                    <FormDialog
                        open={addingGrade}
                        onOpenChange={setAddingGrade}
                        title={`Tarif khusus tingkat · ${row.name}`}
                        description="Tarif per tingkat didahulukan daripada tarif semua tingkat. Menyimpan tingkat yang sudah ada = mengubah nominalnya."
                    >
                        <AddGradeForm
                            action={action}
                            errorBag={`rate-${row.type_id}-grade`}
                            organizationId={organizationId}
                            yearId={yearId}
                            onDone={() => setAddingGrade(false)}
                        />
                    </FormDialog>
                </>
            )}
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
            className="space-y-3"
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
        <ConfirmAction
            trigger={
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    aria-label={`Hapus tarif ${label}`}
                    className="text-destructive"
                >
                    <Trash2 />
                    Hapus tarif {label.toLowerCase()}
                </Button>
            }
            title={`Hapus tarif ${label.toLowerCase()}?`}
            description="Tagihan yang sudah dibuat tidak berubah."
            action={`/keuangan/tarif/${rateId}`}
            method="delete"
            confirmLabel="Hapus tarif"
            errorBag={`remove-${rateId}`}
        />
    );
}

/**
 * Pembayaran online Midtrans (PRD-006 §6, MG-1): metode VA bank + QRIS yang dibuka dan biaya layanan per metode
 * (ditanggung orang tua, OD-MG-03). Kunci Midtrans dipasang di .env server, bukan di sini.
 */
function OnlinePaymentsSection({ data }: { data: OnlinePayments }) {
    const active = data.configured && data.methods.some((row) => row.enabled);

    return (
        <section aria-label="Pembayaran online" className="max-w-3xl space-y-4">
            <div className="space-y-2 rounded-xl border p-4 text-sm">
                <p className="flex flex-wrap items-center gap-2 font-medium">
                    Status:
                    {active ? (
                        <Badge>Aktif</Badge>
                    ) : (
                        <Badge variant="outline">Belum aktif</Badge>
                    )}
                    {data.configured && (
                        <Badge variant="secondary">
                            {data.production
                                ? 'Akun produksi'
                                : 'Sandbox (uji coba)'}
                        </Badge>
                    )}
                </p>
                {data.configured ? (
                    <p className="text-muted-foreground">
                        Kunci Midtrans sudah terpasang di server. Pembayaran
                        online aktif bila minimal satu metode dibuka.
                    </p>
                ) : (
                    <p className="text-muted-foreground">
                        Kunci Midtrans belum dipasang. Pengelola server mengisi{' '}
                        <code>MIDTRANS_SERVER_KEY</code> dan{' '}
                        <code>MIDTRANS_CLIENT_KEY</code> di file{' '}
                        <code>.env</code>; setelah itu metode bisa dibuka di
                        sini.
                    </p>
                )}
                <p className="text-muted-foreground">
                    Biaya layanan ditanggung orang tua: ditambahkan di atas
                    tagihan dan ditampilkan sebelum bayar. Isi sesuai tarif di
                    kontrak Midtrans yayasan.
                </p>
            </div>

            <Form
                action="/keuangan/pembayaran-online/pengaturan"
                method="post"
                errorBag="onlinePayments"
                options={{ preserveScroll: true }}
                className="space-y-3"
            >
                {({ processing, errors }) => (
                    <>
                        <ul className="divide-y rounded-xl border">
                            {data.methods.map((row) => (
                                <OnlineMethodRow
                                    key={row.method}
                                    row={row}
                                    disabled={!data.configured}
                                    maxFixedFee={data.maxFixedFee}
                                    error={
                                        (
                                            errors as Record<
                                                string,
                                                string | undefined
                                            >
                                        )[`methods.${row.method}.fee`]
                                    }
                                />
                            ))}
                        </ul>
                        <InputError message={errors.form} />
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Menyimpan…' : 'Simpan'}
                        </Button>
                    </>
                )}
            </Form>
        </section>
    );
}

function OnlineMethodRow({
    row,
    disabled,
    maxFixedFee,
    error,
}: {
    row: OnlineMethod;
    disabled: boolean;
    maxFixedFee: number;
    error?: string;
}) {
    const [enabled, setEnabled] = useState(row.enabled);
    const id = `online-${row.method}`;

    return (
        <li className="grid gap-3 p-3 sm:grid-cols-[minmax(0,1fr)_14rem] sm:items-center">
            <div className="space-y-0.5">
                <input
                    type="hidden"
                    name={`methods[${row.method}][enabled]`}
                    value={enabled ? '1' : '0'}
                />
                <label className="flex items-center gap-2 text-sm font-medium">
                    <input
                        type="checkbox"
                        checked={enabled}
                        disabled={disabled && !enabled}
                        onChange={(event) => setEnabled(event.target.checked)}
                        className="size-4 accent-foreground"
                    />
                    {row.label}
                </label>
                <p className="text-xs text-muted-foreground">
                    Saat ini: {row.enabled ? 'dibuka' : 'ditutup'} ·{' '}
                    {row.fee_label}
                </p>
            </div>
            <div className="grid gap-1">
                <Label htmlFor={`${id}-fee`} className="text-xs">
                    {row.fee_is_percent
                        ? 'Biaya layanan (% dari tagihan)'
                        : `Biaya layanan (Rp, maks ${rupiah.format(maxFixedFee)})`}
                </Label>
                <Input
                    id={`${id}-fee`}
                    name={`methods[${row.method}][fee]`}
                    defaultValue={row.fee_input}
                    inputMode="decimal"
                    maxLength={10}
                    placeholder={row.fee_is_percent ? 'mis. 0,7' : 'mis. 4000'}
                />
                <InputError message={error} />
            </div>
        </li>
    );
}
