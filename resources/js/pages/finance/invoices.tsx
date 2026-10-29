import { Form, Head, router } from '@inertiajs/react';
import { FilePlus2, Search, XCircle } from 'lucide-react';
import { useState } from 'react';
import type { StudentHit } from '@/components/finance/student-search';
import { StudentSearch } from '@/components/finance/student-search';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type InvoiceRow = {
    id: string;
    enrollment_id: string;
    name: string;
    class: string | null;
    student_number: string | null;
    active: boolean;
    fee_type: string;
    description: string;
    period: string | null;
    base_amount: number;
    discount_amount: number;
    discount_label: string | null;
    amount: number;
    paid_amount: number;
    due_date: string;
    status: 'TERBUKA' | 'LUNAS' | 'BATAL';
    status_label: string;
    overdue: boolean;
    void_reason: string | null;
};

type Filters = {
    type: string | null;
    month: string | null;
    status: string | null;
    q: string | null;
};

type Summary = {
    type: string;
    months: string[];
    students: number;
    created: number;
    existing: number;
    free: number;
    total: number;
    total_label: string;
    missing: { name: string; class: string | null }[];
    missing_count: number;
};

type Props = {
    organizations: Option[];
    organizationId: string | null;
    years: Option[];
    yearId: string | null;
    filters: Filters;
    invoices: {
        rows: InvoiceRow[];
        page: number;
        last_page: number;
        total: number;
        sums: { amount: number; paid: number; outstanding: number };
    } | null;
    options: {
        types: Option[];
        monthlyTypes: Option[];
        onceTypes: Option[];
        classes: Option[];
        months: Option[];
        statuses: Option[];
    };
    canManage: boolean;
    voidReasonMax: number;
};

const rupiah = new Intl.NumberFormat('id-ID');
const formatRupiah = (amount: number) => `Rp ${rupiah.format(amount)}`;

const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' });

function formatDate(value: string): string {
    const [year, month, day] = value.split('-').map(Number);

    return dateFormat.format(new Date(year, month - 1, day));
}

const selectClass =
    'h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

/**
 * Tagihan siswa (PRD-003 §4, §6, FN-2a): daftar per lembaga × tahun ajaran dengan saringan, buat tagihan
 * bulanan (pratinjau dulu), dan batalkan tagihan. Server tetap memeriksa ulang setiap aksi.
 */
export default function Invoices({
    organizations,
    organizationId,
    years,
    yearId,
    filters,
    invoices,
    options,
    canManage,
    voidReasonMax,
}: Props) {
    const [generating, setGenerating] = useState<'bulanan' | 'sekali' | null>(
        null,
    );
    const [query, setQuery] = useState(filters.q ?? '');

    const params = (overrides: Record<string, string | null>) => {
        const merged: Record<string, string | null> = {
            lembaga: organizationId,
            tahun: yearId,
            jenis: filters.type,
            bulan: filters.month,
            status: filters.status,
            q: filters.q,
            ...overrides,
        };
        const clean: Record<string, string> = {};

        for (const [key, value] of Object.entries(merged)) {
            if (value) {
                clean[key] = value;
            }
        }

        return clean;
    };

    const visit = (overrides: Record<string, string | null>) =>
        router.get('/keuangan/tagihan', params(overrides), {
            preserveScroll: true,
        });

    return (
        <>
            <Head title="Tagihan" />

            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Tagihan"
                    description="Tagihan siswa per lembaga & tahun ajaran. Nominal disalin saat tagihan dibuat; perubahan tarif atau potongan sesudahnya tidak mengubah tagihan yang sudah ada."
                />

                <div className="grid gap-3 sm:grid-cols-2">
                    <SelectField
                        id="inv-organization"
                        label="Lembaga"
                        value={organizationId ?? ''}
                        options={organizations}
                        onChange={(value) =>
                            visit({
                                lembaga: value,
                                tahun: null,
                                bulan: null,
                                halaman: null,
                            })
                        }
                    />
                    <SelectField
                        id="inv-year"
                        label="Tahun ajaran"
                        value={yearId ?? ''}
                        options={years}
                        onChange={(value) =>
                            visit({ tahun: value, bulan: null, halaman: null })
                        }
                    />
                </div>

                {yearId === null || invoices === null ? (
                    <Empty>
                        Kalender akademik lembaga ini belum punya tahun ajaran.
                    </Empty>
                ) : (
                    <>
                        {canManage &&
                            (generating === 'bulanan' ? (
                                <GenerateForm
                                    organizationId={organizationId ?? ''}
                                    yearId={yearId}
                                    types={options.monthlyTypes}
                                    months={options.months}
                                    onDone={() => setGenerating(null)}
                                />
                            ) : generating === 'sekali' ? (
                                <OnceForm
                                    organizationId={organizationId ?? ''}
                                    yearId={yearId}
                                    types={options.onceTypes}
                                    classes={options.classes}
                                    onDone={() => setGenerating(null)}
                                />
                            ) : (
                                <div className="grid grid-cols-[repeat(auto-fit,minmax(13rem,1fr))] gap-2 sm:flex">
                                    <Button
                                        type="button"
                                        onClick={() => setGenerating('bulanan')}
                                    >
                                        <FilePlus2 />
                                        Buat tagihan bulanan
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setGenerating('sekali')}
                                    >
                                        <FilePlus2 />
                                        Buat tagihan sekali bayar
                                    </Button>
                                </div>
                            ))}

                        <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-3">
                            <SelectField
                                id="inv-type"
                                label="Jenis biaya"
                                value={filters.type ?? ''}
                                options={[
                                    { value: '', label: 'Semua jenis' },
                                    ...options.types,
                                ]}
                                onChange={(value) =>
                                    visit({
                                        jenis: value || null,
                                        halaman: null,
                                    })
                                }
                            />
                            <SelectField
                                id="inv-month"
                                label="Bulan"
                                value={filters.month ?? ''}
                                options={[
                                    { value: '', label: 'Semua bulan' },
                                    ...options.months,
                                ]}
                                onChange={(value) =>
                                    visit({
                                        bulan: value || null,
                                        halaman: null,
                                    })
                                }
                            />
                            <SelectField
                                id="inv-status"
                                label="Status"
                                value={filters.status ?? ''}
                                options={[
                                    { value: '', label: 'Semua status' },
                                    ...options.statuses,
                                ]}
                                onChange={(value) =>
                                    visit({
                                        status: value || null,
                                        halaman: null,
                                    })
                                }
                            />
                            <form
                                className="grid gap-1.5"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    visit({
                                        q: query.trim() || null,
                                        halaman: null,
                                    });
                                }}
                            >
                                <Label htmlFor="inv-q">Siswa</Label>
                                <div className="grid grid-cols-[minmax(0,1fr)_auto] gap-2">
                                    <Input
                                        id="inv-q"
                                        value={query}
                                        maxLength={100}
                                        placeholder="Nama / NIS"
                                        onChange={(event) =>
                                            setQuery(event.target.value)
                                        }
                                    />
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        size="icon"
                                        aria-label="Cari siswa"
                                    >
                                        <Search />
                                    </Button>
                                </div>
                            </form>
                        </div>

                        <dl className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-3">
                            <Stat
                                label="Jumlah tagihan"
                                value={String(invoices.total)}
                            />
                            <Stat
                                label="Total tagihan (tanpa yang batal)"
                                value={formatRupiah(invoices.sums.amount)}
                            />
                            <Stat
                                label="Sudah dibayar"
                                value={formatRupiah(invoices.sums.paid)}
                            />
                            <Stat
                                label="Sisa"
                                value={formatRupiah(invoices.sums.outstanding)}
                            />
                        </dl>

                        {invoices.rows.length === 0 ? (
                            <Empty>
                                {filters.q
                                    ? 'Tidak ada tagihan untuk siswa aktif yang cocok.'
                                    : 'Belum ada tagihan dengan saringan ini.'}
                            </Empty>
                        ) : (
                            <ul className="divide-y rounded-xl border">
                                {invoices.rows.map((invoice) => (
                                    <InvoiceItem
                                        key={invoice.id}
                                        invoice={invoice}
                                        canManage={canManage}
                                        voidReasonMax={voidReasonMax}
                                    />
                                ))}
                            </ul>
                        )}

                        {invoices.last_page > 1 && (
                            <nav
                                aria-label="Halaman"
                                className="flex flex-wrap items-center justify-between gap-2 text-sm"
                            >
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={invoices.page <= 1}
                                    onClick={() =>
                                        visit({
                                            halaman: String(invoices.page - 1),
                                        })
                                    }
                                >
                                    Sebelumnya
                                </Button>
                                <span className="text-muted-foreground">
                                    Halaman {invoices.page} dari{' '}
                                    {invoices.last_page}
                                </span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={
                                        invoices.page >= invoices.last_page
                                    }
                                    onClick={() =>
                                        visit({
                                            halaman: String(invoices.page + 1),
                                        })
                                    }
                                >
                                    Berikutnya
                                </Button>
                            </nav>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

function InvoiceItem({
    invoice,
    canManage,
    voidReasonMax,
}: {
    invoice: InvoiceRow;
    canManage: boolean;
    voidReasonMax: number;
}) {
    const [voiding, setVoiding] = useState(false);
    const canVoid =
        canManage && invoice.status !== 'BATAL' && invoice.paid_amount === 0;

    return (
        <li className="space-y-2 p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0 space-y-0.5">
                    <p className="font-medium break-words">
                        {invoice.name}
                        {invoice.class && (
                            <span className="ml-2 text-sm font-normal text-muted-foreground">
                                {invoice.class}
                            </span>
                        )}
                    </p>
                    <p
                        className={
                            'text-sm break-words ' +
                            (invoice.status === 'BATAL'
                                ? 'text-muted-foreground line-through'
                                : '')
                        }
                    >
                        {invoice.description}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        Jatuh tempo {formatDate(invoice.due_date)}
                        {invoice.discount_amount > 0 &&
                            ` · tarif ${formatRupiah(invoice.base_amount)}, potongan ${invoice.discount_label ?? formatRupiah(invoice.discount_amount)}`}
                    </p>
                    {invoice.void_reason && (
                        <p className="text-xs text-muted-foreground">
                            Dibatalkan: {invoice.void_reason}
                        </p>
                    )}
                </div>
                <div className="flex flex-col items-end gap-1 text-right">
                    <span className="font-medium tabular-nums">
                        {formatRupiah(invoice.amount)}
                    </span>
                    <span className="flex flex-wrap justify-end gap-1">
                        <Badge
                            variant={
                                invoice.status === 'LUNAS'
                                    ? 'default'
                                    : invoice.status === 'BATAL'
                                      ? 'outline'
                                      : 'secondary'
                            }
                        >
                            {invoice.status_label}
                        </Badge>
                        {invoice.overdue && (
                            <Badge variant="destructive">
                                Lewat jatuh tempo
                            </Badge>
                        )}
                    </span>
                </div>
            </div>

            {canVoid &&
                (voiding ? (
                    <Form
                        action={`/keuangan/tagihan/${invoice.id}/batalkan`}
                        method="post"
                        errorBag={`void-${invoice.id}`}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setVoiding(false)}
                        className="space-y-2 rounded-md border border-destructive/40 p-3"
                    >
                        {({ processing, errors }) => (
                            <>
                                <Label htmlFor={`void-${invoice.id}`}>
                                    Alasan pembatalan
                                </Label>
                                <Input
                                    id={`void-${invoice.id}`}
                                    name="reason"
                                    maxLength={voidReasonMax}
                                    placeholder="mis. Salah tingkat, siswa pindah"
                                    required
                                />
                                <InputError
                                    message={errors.reason ?? errors.form}
                                />
                                <div className="grid grid-cols-[repeat(auto-fit,minmax(8rem,1fr))] gap-2 sm:flex">
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="destructive"
                                        disabled={processing}
                                    >
                                        Batalkan tagihan
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setVoiding(false)}
                                    >
                                        Kembali
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                ) : (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="text-destructive"
                        onClick={() => setVoiding(true)}
                    >
                        <XCircle />
                        Batalkan
                    </Button>
                ))}
        </li>
    );
}

/**
 * Buat tagihan bulanan: pilih jenis biaya & rentang bulan → Pratinjau (tanpa menyimpan) → Buat.
 */
/**
 * Minta pratinjau (JSON). Mengembalikan ringkasan, atau pesan yang aman ditampilkan.
 */
async function requestPreview(
    url: string,
    params: Record<string, string>,
): Promise<{ summary?: Summary; message?: string }> {
    try {
        const response = await fetch(
            `${url}?${new URLSearchParams(params).toString()}`,
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        );

        if (response.status === 429) {
            return {
                message: 'Terlalu sering. Tunggu sebentar lalu coba lagi.',
            };
        }

        const data = (await response.json()) as {
            summary?: Summary;
            message?: string;
            errors?: Record<string, string[]>;
        };

        if (!response.ok || !data.summary) {
            const first = data.errors
                ? Object.values(data.errors)[0]?.[0]
                : undefined;

            return { message: first ?? data.message ?? 'Pratinjau gagal.' };
        }

        return { summary: data.summary };
    } catch {
        return {
            message:
                'Tidak bisa terhubung ke server. Periksa koneksi lalu coba lagi.',
        };
    }
}

function GenerateForm({
    organizationId,
    yearId,
    types,
    months,
    onDone,
}: {
    organizationId: string;
    yearId: string;
    types: Option[];
    months: Option[];
    onDone: () => void;
}) {
    const [typeId, setTypeId] = useState(types[0]?.value ?? '');
    const [from, setFrom] = useState(months[0]?.value ?? '');
    const [to, setTo] = useState(months[months.length - 1]?.value ?? '');
    const [summary, setSummary] = useState<Summary | null>(null);
    const [loading, setLoading] = useState(false);
    const [message, setMessage] = useState<string | null>(null);

    const reset = () => {
        setSummary(null);
        setMessage(null);
    };

    const preview = async () => {
        setLoading(true);
        setMessage(null);

        const result = await requestPreview('/keuangan/tagihan/pratinjau', {
            organization_id: organizationId,
            academic_year_id: yearId,
            fee_type_id: typeId,
            from,
            to,
        });

        setSummary(result.summary ?? null);
        setMessage(result.message ?? null);
        setLoading(false);
    };

    if (types.length === 0) {
        return (
            <p className="rounded-xl border border-dashed p-4 text-sm text-muted-foreground">
                Belum ada jenis biaya bulanan yang aktif. Minta Admin Keuangan
                menambahkannya di Pengaturan keuangan.
                <Button type="button" variant="link" size="sm" onClick={onDone}>
                    Tutup
                </Button>
            </p>
        );
    }

    return (
        <Form
            action="/keuangan/tagihan/bulanan"
            method="post"
            errorBag="generate"
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-4 rounded-xl border border-primary/30 bg-muted/20 p-4"
        >
            {({ processing, errors }) => (
                <>
                    <div className="space-y-1">
                        <h2 className="text-base font-medium">
                            Buat tagihan bulanan
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Untuk semua siswa yang terdaftar di lembaga ini pada
                            bulan itu. Tagihan yang sudah ada dilewati, jadi
                            aman diulang (mis. untuk siswa baru).
                        </p>
                    </div>

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

                    <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-3">
                        <SelectField
                            id="gen-type"
                            name="fee_type_id"
                            label="Jenis biaya"
                            value={typeId}
                            options={types}
                            onChange={(value) => {
                                setTypeId(value);
                                reset();
                            }}
                        />
                        <SelectField
                            id="gen-from"
                            name="from"
                            label="Dari bulan"
                            value={from}
                            options={months}
                            onChange={(value) => {
                                setFrom(value);
                                reset();
                            }}
                        />
                        <SelectField
                            id="gen-to"
                            name="to"
                            label="Sampai bulan"
                            value={to}
                            options={months}
                            onChange={(value) => {
                                setTo(value);
                                reset();
                            }}
                        />
                    </div>

                    <InputError
                        message={
                            message ??
                            errors.fee_type_id ??
                            errors.from ??
                            errors.to ??
                            errors.academic_year_id ??
                            errors.form
                        }
                    />

                    {summary && <SummaryBox summary={summary} />}

                    <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
                        {summary === null ? (
                            <Button
                                type="button"
                                onClick={() => void preview()}
                                disabled={loading || typeId === ''}
                            >
                                {loading ? 'Menghitung…' : 'Pratinjau'}
                            </Button>
                        ) : (
                            <Button
                                type="submit"
                                disabled={processing || summary.created === 0}
                            >
                                {processing
                                    ? 'Membuat…'
                                    : `Buat ${summary.created} tagihan`}
                            </Button>
                        )}
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

/**
 * Buat tagihan sekali bayar (FN-2b): jenis biaya sekali bayar, sasaran (seluruh lembaga / satu kelas /
 * satu siswa), jatuh tempo → Pratinjau → Buat.
 */
function OnceForm({
    organizationId,
    yearId,
    types,
    classes,
    onDone,
}: {
    organizationId: string;
    yearId: string;
    types: Option[];
    classes: Option[];
    onDone: () => void;
}) {
    const [typeId, setTypeId] = useState(types[0]?.value ?? '');
    const [target, setTarget] = useState<'lembaga' | 'kelas' | 'siswa'>(
        'lembaga',
    );
    const [classId, setClassId] = useState(classes[0]?.value ?? '');
    const [student, setStudent] = useState<StudentHit | null>(null);
    const [dueDate, setDueDate] = useState('');
    const [summary, setSummary] = useState<Summary | null>(null);
    const [loading, setLoading] = useState(false);
    const [message, setMessage] = useState<string | null>(null);

    const reset = () => {
        setSummary(null);
        setMessage(null);
    };

    const preview = async () => {
        setLoading(true);
        setMessage(null);

        const result = await requestPreview(
            '/keuangan/tagihan/pratinjau-sekali',
            {
                organization_id: organizationId,
                academic_year_id: yearId,
                fee_type_id: typeId,
                target,
                class_id: target === 'kelas' ? classId : '',
                enrollment_id:
                    target === 'siswa' ? (student?.enrollment_id ?? '') : '',
                due_date: dueDate,
            },
        );

        setSummary(result.summary ?? null);
        setMessage(result.message ?? null);
        setLoading(false);
    };

    if (types.length === 0) {
        return (
            <p className="rounded-xl border border-dashed p-4 text-sm text-muted-foreground">
                Belum ada jenis biaya sekali bayar yang aktif. Minta Admin
                Keuangan menambahkannya di Pengaturan keuangan.
                <Button type="button" variant="link" size="sm" onClick={onDone}>
                    Tutup
                </Button>
            </p>
        );
    }

    return (
        <Form
            action="/keuangan/tagihan/sekali"
            method="post"
            errorBag="generateOnce"
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-4 rounded-xl border border-primary/30 bg-muted/20 p-4"
        >
            {({ processing, errors }) => (
                <>
                    <div className="space-y-1">
                        <h2 className="text-base font-medium">
                            Buat tagihan sekali bayar
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Mis. daftar ulang atau seragam. Satu tagihan per
                            siswa per jenis biaya dalam tahun ajaran ini; yang
                            sudah ada dilewati.
                        </p>
                    </div>

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
                    <input type="hidden" name="target" value={target} />
                    {target === 'kelas' && (
                        <input type="hidden" name="class_id" value={classId} />
                    )}
                    {target === 'siswa' && (
                        <input
                            type="hidden"
                            name="enrollment_id"
                            value={student?.enrollment_id ?? ''}
                        />
                    )}

                    <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-3">
                        <SelectField
                            id="once-type"
                            name="fee_type_id"
                            label="Jenis biaya"
                            value={typeId}
                            options={types}
                            onChange={(value) => {
                                setTypeId(value);
                                reset();
                            }}
                        />
                        <div className="grid min-w-0 gap-1.5">
                            <Label htmlFor="once-due">Jatuh tempo</Label>
                            <Input
                                id="once-due"
                                name="due_date"
                                type="date"
                                value={dueDate}
                                onChange={(event) => {
                                    setDueDate(event.target.value);
                                    reset();
                                }}
                                required
                            />
                        </div>
                    </div>

                    <div
                        role="radiogroup"
                        aria-label="Sasaran"
                        className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2"
                    >
                        {(
                            [
                                ['lembaga', 'Seluruh lembaga'],
                                ['kelas', 'Satu kelas'],
                                ['siswa', 'Satu siswa'],
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
                                    checked={target === value}
                                    onChange={() => {
                                        setTarget(value);
                                        reset();
                                    }}
                                    className="size-4 accent-primary"
                                />
                                {label}
                            </label>
                        ))}
                    </div>

                    {target === 'kelas' &&
                        (classes.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Belum ada kelas aktif di tahun ajaran ini.
                            </p>
                        ) : (
                            <SelectField
                                id="once-class"
                                label="Kelas"
                                value={classId}
                                options={classes}
                                onChange={(value) => {
                                    setClassId(value);
                                    reset();
                                }}
                            />
                        ))}

                    {target === 'siswa' &&
                        (student ? (
                            <div className="flex flex-wrap items-center justify-between gap-2 rounded-md border bg-background p-3 text-sm">
                                <span className="min-w-0 font-medium break-words">
                                    {student.name}
                                    {student.class && (
                                        <span className="ml-2 font-normal text-muted-foreground">
                                            {student.class}
                                        </span>
                                    )}
                                </span>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() => {
                                        setStudent(null);
                                        reset();
                                    }}
                                >
                                    Ganti siswa
                                </Button>
                            </div>
                        ) : (
                            <StudentSearch
                                id="once-student"
                                organizationId={organizationId}
                                onSelect={(hit) => {
                                    setStudent(hit);
                                    reset();
                                }}
                            />
                        ))}

                    <InputError
                        message={
                            message ??
                            errors.fee_type_id ??
                            errors.due_date ??
                            errors.class_id ??
                            errors.enrollment_id ??
                            errors.target ??
                            errors.academic_year_id ??
                            errors.form
                        }
                    />

                    {summary && <SummaryBox summary={summary} once />}

                    <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
                        {summary === null ? (
                            <Button
                                type="button"
                                onClick={() => void preview()}
                                disabled={
                                    loading ||
                                    typeId === '' ||
                                    dueDate === '' ||
                                    (target === 'siswa' && student === null)
                                }
                            >
                                {loading ? 'Menghitung…' : 'Pratinjau'}
                            </Button>
                        ) : (
                            <Button
                                type="submit"
                                disabled={processing || summary.created === 0}
                            >
                                {processing
                                    ? 'Membuat…'
                                    : `Buat ${summary.created} tagihan`}
                            </Button>
                        )}
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

function SummaryBox({
    summary,
    once = false,
}: {
    summary: Summary;
    once?: boolean;
}) {
    const months =
        summary.months.length === 1
            ? summary.months[0]
            : `${summary.months[0]} – ${summary.months[summary.months.length - 1]}`;

    return (
        <div className="space-y-2 rounded-md border bg-background p-3 text-sm">
            <p className="font-medium">
                {summary.type},{' '}
                {once
                    ? `tahun ajaran ${months}`
                    : `${months} (${summary.months.length} bulan)`}
            </p>
            <ul className="list-disc space-y-0.5 pl-5">
                <li>{summary.students} siswa terdaftar</li>
                <li>
                    <span className="font-medium">
                        {summary.created} tagihan baru
                    </span>
                    , total {summary.total_label}
                    {summary.free > 0 &&
                        ` (${summary.free} langsung lunas karena potongan 100%)`}
                </li>
                {summary.existing > 0 && (
                    <li>{summary.existing} tagihan sudah ada (dilewati)</li>
                )}
            </ul>
            {summary.missing_count > 0 && (
                <div className="rounded-md border border-amber-500/50 bg-amber-50 p-2 text-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                    {summary.missing_count} siswa dilewati karena belum ada
                    tarif untuk tingkatnya (atau belum masuk kelas dan tidak ada
                    tarif semua tingkat):{' '}
                    {summary.missing
                        .map((student) =>
                            student.class
                                ? `${student.name} (${student.class})`
                                : student.name,
                        )
                        .join(', ')}
                    {summary.missing_count > summary.missing.length && ', …'}
                </div>
            )}
        </div>
    );
}

function SelectField({
    id,
    name,
    label,
    value,
    options,
    onChange,
}: {
    id: string;
    name?: string;
    label: string;
    value: string;
    options: Option[];
    onChange: (value: string) => void;
}) {
    return (
        <div className="grid min-w-0 gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <select
                id={id}
                name={name}
                className={selectClass}
                value={value}
                onChange={(event) => onChange(event.target.value)}
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </div>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-xl border p-3">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-lg font-semibold tabular-nums">{value}</dd>
        </div>
    );
}

function Empty({ children }: { children: React.ReactNode }) {
    return (
        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
            {children}
        </p>
    );
}
