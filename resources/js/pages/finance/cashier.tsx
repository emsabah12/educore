import { Form, Head, Link, router } from '@inertiajs/react';
import {
    ArrowDownToLine,
    ArrowUpFromLine,
    CircleAlert,
    Printer,
    XCircle,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { FormDialog } from '@/components/form-dialog';
import { formatDateTime } from '@/components/finance/online-order';
import type { OnlineOrder } from '@/components/finance/online-order';
import { StudentSearch } from '@/components/finance/student-search';
import Heading from '@/components/heading';
import { PageTabs, usePageTab } from '@/components/page-tabs';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type OpenInvoice = {
    id: string;
    description: string;
    due_date: string;
    amount: number;
    paid_amount: number;
    outstanding: number;
    overdue: boolean;
};

type PaymentRow = {
    id: string;
    receipt_number: string;
    paid_on: string;
    method: string;
    amount: number;
    status: 'SAH' | 'BATAL';
    void_reason: string | null;
    items: string[];
};

type SavingsRow = {
    id: string;
    slip_number: string;
    entry_date: string;
    kind: 'SETOR' | 'TARIK' | 'BAYAR_TAGIHAN';
    kind_label: string;
    method: string | null;
    amount: number;
    balance_after: number;
    taken_by: string | null;
    note: string | null;
    status: 'SAH' | 'BATAL';
    void_reason: string | null;
};

type SavingsData = {
    balance: number;
    entries: SavingsRow[];
};

type SavingsLimits = { note: number; takenBy: number; reason: number };

type StudentData = {
    student: {
        enrollment_id: string;
        student_id: string;
        name: string;
        class: string | null;
        student_number: string | null;
        nisn: string | null;
        active: boolean;
    };
    invoices: OpenInvoice[];
    outstanding: number;
    payments: PaymentRow[];
};

type Props = {
    organizations: Option[];
    organizationId: string | null;
    student: StudentData | null;
    /** Tabungan siswa (PRD-005); null bila tanpa izin lihat tabungan. */
    savings: SavingsData | null;
    canSave: boolean;
    savingsLimits: SavingsLimits;
    /** Pesanan bayar online siswa ini yang masih menunggu (PRD-006 MG-3). */
    onlinePending: OnlineOrder | null;
    canPay: boolean;
    today: string;
    methods: Option[];
    noteMax: number;
    voidReasonMax: number;
};

const rupiah = new Intl.NumberFormat('id-ID');
const formatRupiah = (amount: number) => `Rp ${rupiah.format(amount)}`;

const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' });

const TABS = ['bayar', 'riwayat', 'tabungan'] as const;

function formatDate(value: string): string {
    const [year, month, day] = value.split('-').map(Number);

    return dateFormat.format(new Date(year, month - 1, day));
}

const selectClass =
    'h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

/**
 * Kasir (PRD-003 §4, §6, FN-3): cari siswa → tagihan belum lunas → catat pembayaran (boleh cicil) →
 * kuitansi. Riwayat pembayaran & batal pembayaran. Server tetap memeriksa ulang setiap aksi.
 */
export default function Cashier({
    organizations,
    organizationId,
    student,
    savings,
    canSave,
    savingsLimits,
    onlinePending,
    canPay,
    today,
    methods,
    noteMax,
    voidReasonMax,
}: Props) {
    const open = (enrollmentId: string | null, lembaga = organizationId) =>
        router.get(
            '/keuangan/kasir',
            {
                ...(lembaga ? { lembaga } : {}),
                ...(enrollmentId ? { siswa: enrollmentId } : {}),
            },
            { preserveScroll: false },
        );

    return (
        <>
            <Head title="Kasir" />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Kasir"
                    description="Catat pembayaran tagihan siswa (tunai atau transfer) dan cetak kuitansinya. Boleh dicicil; kelebihan bayar belum bisa diterima."
                />

                <div className="grid gap-1.5 sm:max-w-sm">
                    <Label htmlFor="cashier-organization">Lembaga</Label>
                    <select
                        id="cashier-organization"
                        className={selectClass}
                        value={organizationId ?? ''}
                        onChange={(event) => open(null, event.target.value)}
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

                {organizationId !== null && (
                    <div className="rounded-xl border p-4">
                        <StudentSearch
                            id="cashier-student"
                            url="/keuangan/kasir/cari"
                            organizationId={organizationId}
                            onSelect={(hit) => open(hit.enrollment_id)}
                        />
                    </div>
                )}

                {student === null ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Cari siswa untuk melihat tagihannya.
                    </p>
                ) : (
                    <>
                        {onlinePending && (
                            <div className="flex gap-2 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                                <CircleAlert className="mt-0.5 size-4 shrink-0" />
                                <p>
                                    Orang tua sedang membayar online{' '}
                                    {formatRupiah(onlinePending.gross_amount)}{' '}
                                    lewat {onlinePending.method_label} (batas{' '}
                                    {formatDateTime(onlinePending.expires_at)})
                                    untuk:{' '}
                                    {onlinePending.invoices
                                        .map((invoice) => invoice.description)
                                        .join(', ')}
                                    . Bila tagihan yang sama dibayar di kasir
                                    dan pembayaran online juga masuk, akan ada
                                    kelebihan bayar yang harus dikembalikan.{' '}
                                    <Link
                                        href={`/keuangan/pembayaran-online/${onlinePending.id}`}
                                        className="font-medium underline underline-offset-4"
                                    >
                                        Lihat
                                    </Link>
                                </p>
                            </div>
                        )}
                        <StudentPanel
                            key={student.student.enrollment_id}
                            data={student}
                            savings={savings}
                            canSave={canSave}
                            savingsLimits={savingsLimits}
                            organizationId={organizationId ?? ''}
                            canPay={canPay}
                            today={today}
                            methods={methods}
                            noteMax={noteMax}
                            voidReasonMax={voidReasonMax}
                        />
                    </>
                )}
            </div>
        </>
    );
}

function StudentPanel({
    data,
    savings,
    canSave,
    savingsLimits,
    organizationId,
    canPay,
    today,
    methods,
    noteMax,
    voidReasonMax,
}: {
    data: StudentData;
    savings: SavingsData | null;
    canSave: boolean;
    savingsLimits: SavingsLimits;
    organizationId: string;
    canPay: boolean;
    today: string;
    methods: Option[];
    noteMax: number;
    voidReasonMax: number;
}) {
    const { student, invoices, outstanding, payments } = data;
    const identity = [
        student.class,
        student.student_number ? `NIS ${student.student_number}` : null,
        student.nisn ? `NISN ${student.nisn}` : null,
    ]
        .filter(Boolean)
        .join(' · ');
    const [requestedTab, setTab] = usePageTab(TABS, 'bayar');
    // Tab Tabungan hanya ada bila boleh melihat tabungan.
    const tab =
        requestedTab === 'tabungan' && savings === null
            ? 'bayar'
            : requestedTab;

    return (
        <>
            <section className="space-y-3 rounded-xl border p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="min-w-0">
                        <h2 className="text-lg font-medium break-words">
                            {student.name}
                            {!student.active && (
                                <Badge variant="outline" className="ml-2">
                                    Sudah keluar
                                </Badge>
                            )}
                        </h2>
                        {identity && (
                            <p className="text-sm text-muted-foreground">
                                {identity}
                            </p>
                        )}
                    </div>
                    <div className="text-right">
                        <p className="text-xs text-muted-foreground">
                            Sisa tagihan
                        </p>
                        <p className="text-xl font-semibold tabular-nums">
                            {formatRupiah(outstanding)}
                        </p>
                    </div>
                </div>

                <PageTabs
                    label="Bagian kasir"
                    tabs={[
                        {
                            value: 'bayar',
                            label: canPay ? 'Tagihan & bayar' : 'Tagihan',
                            badge: invoices.length,
                        },
                        {
                            value: 'riwayat',
                            label: `Riwayat pembayaran (${payments.length})`,
                        },
                        ...(savings !== null
                            ? [
                                  {
                                      value: 'tabungan' as const,
                                      label: 'Tabungan',
                                  },
                              ]
                            : []),
                    ]}
                    value={tab}
                    onChange={setTab}
                />

                {tab === 'tabungan' && savings !== null ? (
                    <SavingsPanel
                        savings={savings}
                        organizationId={organizationId}
                        enrollmentId={student.enrollment_id}
                        active={student.active}
                        canSave={canSave}
                        today={today}
                        methods={methods}
                        limits={savingsLimits}
                    />
                ) : tab === 'riwayat' ? (
                    payments.length === 0 ? (
                        <p className="rounded-xl border border-dashed p-4 text-center text-sm text-muted-foreground">
                            Belum ada pembayaran.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-xl border">
                            {payments.map((payment) => (
                                <PaymentItem
                                    key={payment.id}
                                    payment={payment}
                                    canPay={canPay}
                                    voidReasonMax={voidReasonMax}
                                />
                            ))}
                        </ul>
                    )
                ) : invoices.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Tidak ada tagihan yang belum lunas.
                    </p>
                ) : canPay ? (
                    <PaymentForm
                        organizationId={organizationId}
                        enrollmentId={student.enrollment_id}
                        invoices={invoices}
                        today={today}
                        methods={methods}
                        noteMax={noteMax}
                        savingsBalance={
                            canSave && savings !== null && savings.balance > 0
                                ? savings.balance
                                : null
                        }
                    />
                ) : (
                    <InvoiceList invoices={invoices} />
                )}
            </section>
        </>
    );
}

function InvoiceList({ invoices }: { invoices: OpenInvoice[] }) {
    return (
        <ul className="divide-y rounded-lg border">
            {invoices.map((invoice) => (
                <li
                    key={invoice.id}
                    className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm"
                >
                    <InvoiceText invoice={invoice} />
                    <span className="font-medium tabular-nums">
                        {formatRupiah(invoice.outstanding)}
                    </span>
                </li>
            ))}
        </ul>
    );
}

function InvoiceText({ invoice }: { invoice: OpenInvoice }) {
    return (
        <span className="min-w-0 break-words">
            {invoice.description}
            <span className="block text-xs text-muted-foreground">
                Jatuh tempo {formatDate(invoice.due_date)}
                {invoice.paid_amount > 0 &&
                    ` · sudah dibayar ${formatRupiah(invoice.paid_amount)} dari ${formatRupiah(invoice.amount)}`}
                {invoice.overdue && (
                    <Badge variant="destructive" className="ml-2">
                        Lewat jatuh tempo
                    </Badge>
                )}
            </span>
        </span>
    );
}

function PaymentForm({
    organizationId,
    enrollmentId,
    invoices,
    today,
    methods,
    noteMax,
    savingsBalance,
}: {
    organizationId: string;
    enrollmentId: string;
    invoices: OpenInvoice[];
    today: string;
    methods: Option[];
    noteMax: number;
    /** Saldo tabungan yang boleh dipakai (PRD-005 TB-3); null = cara bayar Tabungan tidak ditawarkan. */
    savingsBalance: number | null;
}) {
    // Kosong = semua tagihan, dibayar urut jatuh tempo terlama.
    const [selected, setSelected] = useState<string[]>([]);
    const [amount, setAmount] = useState('');
    const paymentMethods = methods.filter(
        (method) => method.value !== 'TABUNGAN' || savingsBalance !== null,
    );
    const [method, setMethod] = useState(paymentMethods[0]?.value ?? '');
    const fromSavings = method === 'TABUNGAN' && savingsBalance !== null;

    const target = useMemo(() => {
        const chosen =
            selected.length === 0
                ? invoices
                : invoices.filter((invoice) => selected.includes(invoice.id));

        return chosen.reduce((sum, invoice) => sum + invoice.outstanding, 0);
    }, [invoices, selected]);

    const toggle = (id: string) =>
        setSelected((current) =>
            current.includes(id)
                ? current.filter((value) => value !== id)
                : [...current, id],
        );

    return (
        <Form
            action="/keuangan/pembayaran"
            method="post"
            errorBag="payment"
            options={{ preserveScroll: true }}
            className="space-y-4"
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
                        name="enrollment_id"
                        value={enrollmentId}
                    />
                    {selected.map((id) => (
                        <input
                            key={id}
                            type="hidden"
                            name="invoice_ids[]"
                            value={id}
                        />
                    ))}

                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium">
                            Tagihan yang dibayar
                        </legend>
                        <p className="text-xs text-muted-foreground">
                            Tidak memilih = dibayarkan ke tagihan dengan jatuh
                            tempo terlama lebih dulu.
                        </p>
                        <ul className="divide-y rounded-lg border">
                            {invoices.map((invoice) => (
                                <li key={invoice.id}>
                                    <label className="flex cursor-pointer items-start gap-3 px-3 py-2 text-sm hover:bg-muted/40">
                                        <input
                                            type="checkbox"
                                            checked={selected.includes(
                                                invoice.id,
                                            )}
                                            onChange={() => toggle(invoice.id)}
                                            className="mt-0.5 size-4 accent-primary"
                                        />
                                        <span className="flex min-w-0 flex-1 flex-wrap items-start justify-between gap-2">
                                            <InvoiceText invoice={invoice} />
                                            <span className="font-medium tabular-nums">
                                                {formatRupiah(
                                                    invoice.outstanding,
                                                )}
                                            </span>
                                        </span>
                                    </label>
                                </li>
                            ))}
                        </ul>
                        <InputError message={errors.invoice_ids} />
                    </fieldset>

                    <div className="grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-3">
                        <div className="grid content-start gap-1.5">
                            <Label htmlFor="pay-amount">
                                Jumlah bayar (Rp)
                            </Label>
                            <Input
                                id="pay-amount"
                                name="amount"
                                inputMode="numeric"
                                value={amount}
                                onChange={(event) =>
                                    setAmount(event.target.value)
                                }
                                placeholder={rupiah.format(target)}
                                maxLength={20}
                                required
                            />
                            {fromSavings && savingsBalance < target ? (
                                <button
                                    type="button"
                                    className="justify-self-start text-xs font-medium underline underline-offset-4"
                                    onClick={() =>
                                        setAmount(rupiah.format(savingsBalance))
                                    }
                                >
                                    Pakai seluruh saldo:{' '}
                                    {formatRupiah(savingsBalance)}
                                </button>
                            ) : (
                                <button
                                    type="button"
                                    className="justify-self-start text-xs font-medium underline underline-offset-4"
                                    onClick={() =>
                                        setAmount(rupiah.format(target))
                                    }
                                >
                                    Isi lunas: {formatRupiah(target)}
                                </button>
                            )}
                            <InputError message={errors.amount} />
                        </div>
                        <div className="grid content-start gap-1.5">
                            <Label htmlFor="pay-date">Tanggal bayar</Label>
                            <Input
                                id="pay-date"
                                name="paid_on"
                                type="date"
                                defaultValue={today}
                                max={today}
                                required
                            />
                            <InputError message={errors.paid_on} />
                        </div>
                        <div className="grid content-start gap-1.5">
                            <Label htmlFor="pay-method">Cara bayar</Label>
                            <select
                                id="pay-method"
                                name="method"
                                className={selectClass}
                                value={method}
                                onChange={(event) =>
                                    setMethod(event.target.value)
                                }
                            >
                                {paymentMethods.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            {fromSavings && (
                                <p className="text-xs text-muted-foreground">
                                    Saldo tabungan{' '}
                                    {formatRupiah(savingsBalance)}; jumlah bayar
                                    tidak boleh melebihi saldo.
                                </p>
                            )}
                            <InputError message={errors.method} />
                        </div>
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="pay-note">Catatan (opsional)</Label>
                        <Input
                            id="pay-note"
                            name="note"
                            maxLength={noteMax}
                            placeholder="mis. nomor referensi transfer"
                        />
                        <InputError message={errors.note} />
                    </div>

                    <InputError
                        message={
                            errors.form ??
                            errors.enrollment_id ??
                            errors.organization_id
                        }
                    />

                    <Button
                        type="submit"
                        disabled={processing || amount.trim() === ''}
                        className="w-full sm:w-auto"
                    >
                        {processing
                            ? 'Menyimpan…'
                            : 'Simpan pembayaran & buat kuitansi'}
                    </Button>
                </>
            )}
        </Form>
    );
}

function PaymentItem({
    payment,
    canPay,
    voidReasonMax,
}: {
    payment: PaymentRow;
    canPay: boolean;
    voidReasonMax: number;
}) {
    const [voiding, setVoiding] = useState(false);

    return (
        <li className="space-y-2 p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0 space-y-0.5">
                    <p
                        className={
                            'font-mono text-sm ' +
                            (payment.status === 'BATAL'
                                ? 'text-muted-foreground line-through'
                                : '')
                        }
                    >
                        {payment.receipt_number}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {formatDate(payment.paid_on)} · {payment.method}
                    </p>
                    {payment.items.length > 0 && (
                        <p className="text-xs break-words text-muted-foreground">
                            {payment.items.join(', ')}
                        </p>
                    )}
                    {payment.void_reason && (
                        <p className="text-xs text-muted-foreground">
                            Dibatalkan: {payment.void_reason}
                        </p>
                    )}
                </div>
                <div className="flex flex-col items-end gap-1">
                    <span className="font-medium tabular-nums">
                        {formatRupiah(payment.amount)}
                    </span>
                    {payment.status === 'BATAL' && (
                        <Badge variant="outline">Batal</Badge>
                    )}
                </div>
            </div>

            {!voiding && (
                <div className="flex flex-wrap gap-2">
                    <Button asChild size="sm" variant="outline">
                        <Link href={`/keuangan/kuitansi/${payment.id}`}>
                            <Printer />
                            Kuitansi
                        </Link>
                    </Button>
                    {canPay && payment.status === 'SAH' && (
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            className="text-destructive"
                            onClick={() => setVoiding(true)}
                        >
                            <XCircle />
                            Batalkan
                        </Button>
                    )}
                </div>
            )}

            {voiding && (
                <Form
                    action={`/keuangan/pembayaran/${payment.id}/batalkan`}
                    method="post"
                    errorBag={`void-${payment.id}`}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setVoiding(false)}
                    className="space-y-2 rounded-md border border-destructive/40 p-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <Label htmlFor={`void-${payment.id}`}>
                                Alasan pembatalan
                            </Label>
                            <Input
                                id={`void-${payment.id}`}
                                name="reason"
                                maxLength={voidReasonMax}
                                placeholder="mis. Salah input jumlah"
                                required
                            />
                            <p className="text-xs text-muted-foreground">
                                Tagihan yang dibayar dengan kuitansi ini terbuka
                                kembali. Kuitansi tetap tersimpan dengan tanda
                                BATAL.
                            </p>
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
                                    Batalkan pembayaran
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
            )}
        </li>
    );
}

/**
 * Tab Tabungan di Kasir (PRD-005 §4, TB-1): saldo, setor/tarik lewat pop-up, riwayat 30 transaksi terakhir, slip &
 * batal. Server memeriksa ulang saldo, tanggal, dan hak akses.
 */
function SavingsPanel({
    savings,
    organizationId,
    enrollmentId,
    active,
    canSave,
    today,
    methods,
    limits,
}: {
    savings: SavingsData;
    organizationId: string;
    enrollmentId: string;
    active: boolean;
    canSave: boolean;
    today: string;
    methods: Option[];
    limits: SavingsLimits;
}) {
    const [dialog, setDialog] = useState<'deposit' | 'withdraw' | null>(null);
    const close = () => setDialog(null);

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-end justify-between gap-3 rounded-xl border p-4">
                <div>
                    <p className="text-xs text-muted-foreground">
                        Saldo tabungan
                    </p>
                    <p className="text-2xl font-semibold tabular-nums">
                        {formatRupiah(savings.balance)}
                    </p>
                </div>
                {canSave && (
                    <div className="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto">
                        <Button
                            type="button"
                            onClick={() => setDialog('deposit')}
                            disabled={!active}
                        >
                            <ArrowDownToLine />
                            Setor
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setDialog('withdraw')}
                            disabled={savings.balance === 0}
                        >
                            <ArrowUpFromLine />
                            Tarik
                        </Button>
                    </div>
                )}
            </div>
            {canSave && !active && (
                <p className="text-xs text-muted-foreground">
                    Siswa sudah keluar: tidak bisa menabung lagi, tetapi
                    saldonya masih bisa ditarik.
                </p>
            )}

            {savings.entries.length === 0 ? (
                <p className="rounded-xl border border-dashed p-4 text-center text-sm text-muted-foreground">
                    Belum ada transaksi tabungan.
                </p>
            ) : (
                <ul className="divide-y rounded-xl border">
                    {savings.entries.map((entry) => (
                        <SavingsItem
                            key={entry.id}
                            entry={entry}
                            canSave={canSave}
                            reasonMax={limits.reason}
                        />
                    ))}
                </ul>
            )}

            <FormDialog
                open={dialog !== null}
                onOpenChange={(open) => !open && close()}
                title={
                    dialog === 'withdraw' ? 'Tarik tabungan' : 'Setor tabungan'
                }
                description={
                    dialog === 'withdraw'
                        ? `Saldo sekarang ${formatRupiah(savings.balance)}. Penarikan boleh sampai saldo habis.`
                        : 'Uang tabungan dicatat di rekening kas Titipan tabungan lembaga, terpisah dari kas operasional.'
                }
            >
                {dialog !== null && (
                    <SavingsForm
                        kind={dialog}
                        organizationId={organizationId}
                        enrollmentId={enrollmentId}
                        balance={savings.balance}
                        today={today}
                        methods={methods}
                        limits={limits}
                    />
                )}
            </FormDialog>
        </div>
    );
}

function SavingsForm({
    kind,
    organizationId,
    enrollmentId,
    balance,
    today,
    methods,
    limits,
}: {
    kind: 'deposit' | 'withdraw';
    organizationId: string;
    enrollmentId: string;
    balance: number;
    today: string;
    methods: Option[];
    limits: SavingsLimits;
}) {
    const withdraw = kind === 'withdraw';
    const [amount, setAmount] = useState('');

    return (
        <Form
            action={
                withdraw
                    ? '/keuangan/tabungan/tarik'
                    : '/keuangan/tabungan/setor'
            }
            method="post"
            errorBag={withdraw ? 'savingsWithdraw' : 'savingsDeposit'}
            options={{ preserveScroll: true }}
            className="space-y-4"
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
                        name="enrollment_id"
                        value={enrollmentId}
                    />

                    <div className="grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-3">
                        <div className="grid content-start gap-1.5">
                            <Label htmlFor="sav-amount">
                                {withdraw
                                    ? 'Jumlah penarikan (Rp)'
                                    : 'Jumlah setoran (Rp)'}
                            </Label>
                            <Input
                                id="sav-amount"
                                name="amount"
                                inputMode="numeric"
                                value={amount}
                                onChange={(event) =>
                                    setAmount(event.target.value)
                                }
                                maxLength={20}
                                autoFocus
                                required
                            />
                            {withdraw && (
                                <button
                                    type="button"
                                    className="justify-self-start text-xs font-medium underline underline-offset-4"
                                    onClick={() =>
                                        setAmount(rupiah.format(balance))
                                    }
                                >
                                    Tarik semua: {formatRupiah(balance)}
                                </button>
                            )}
                            <InputError message={errors.amount} />
                        </div>
                        <div className="grid content-start gap-1.5">
                            <Label htmlFor="sav-date">Tanggal</Label>
                            <Input
                                id="sav-date"
                                name="entry_date"
                                type="date"
                                defaultValue={today}
                                max={today}
                                required
                            />
                            <InputError message={errors.entry_date} />
                        </div>
                        <div className="grid content-start gap-1.5">
                            <Label htmlFor="sav-method">Cara</Label>
                            <select
                                id="sav-method"
                                name="method"
                                className={selectClass}
                                defaultValue={methods[0]?.value}
                            >
                                {methods
                                    .filter(
                                        (method) => method.value !== 'TABUNGAN',
                                    )
                                    .map((method) => (
                                        <option
                                            key={method.value}
                                            value={method.value}
                                        >
                                            {method.label}
                                        </option>
                                    ))}
                            </select>
                            <InputError message={errors.method} />
                        </div>
                    </div>

                    {withdraw && (
                        <div className="grid gap-1.5">
                            <Label htmlFor="sav-taken-by">Diambil oleh</Label>
                            <Input
                                id="sav-taken-by"
                                name="taken_by"
                                maxLength={limits.takenBy}
                                placeholder="mis. Ibu Siti (ibu)"
                                required
                            />
                            <InputError message={errors.taken_by} />
                        </div>
                    )}

                    <div className="grid gap-1.5">
                        <Label htmlFor="sav-note">Catatan (opsional)</Label>
                        <Input
                            id="sav-note"
                            name="note"
                            maxLength={limits.note}
                            placeholder={
                                withdraw
                                    ? 'mis. untuk study tour'
                                    : 'mis. nomor referensi transfer'
                            }
                        />
                        <InputError message={errors.note} />
                    </div>

                    <InputError
                        message={
                            errors.form ??
                            errors.enrollment_id ??
                            errors.organization_id
                        }
                    />

                    <Button
                        type="submit"
                        disabled={processing || amount.trim() === ''}
                        className="w-full sm:w-auto"
                    >
                        {processing
                            ? 'Menyimpan…'
                            : withdraw
                              ? 'Simpan penarikan & buat slip'
                              : 'Simpan setoran & buat slip'}
                    </Button>
                </>
            )}
        </Form>
    );
}

function SavingsItem({
    entry,
    canSave,
    reasonMax,
}: {
    entry: SavingsRow;
    canSave: boolean;
    reasonMax: number;
}) {
    const [voiding, setVoiding] = useState(false);
    const voided = entry.status === 'BATAL';
    const incoming = entry.kind === 'SETOR';

    return (
        <li className="space-y-2 p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0 space-y-0.5">
                    <p className="text-sm font-medium">
                        {entry.kind_label}
                        <span
                            className={
                                'ml-2 font-mono text-xs font-normal text-muted-foreground ' +
                                (voided ? 'line-through' : '')
                            }
                        >
                            {entry.slip_number}
                        </span>
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {formatDate(entry.entry_date)}
                        {entry.method && ` · ${entry.method}`}
                        {entry.taken_by && ` · diambil ${entry.taken_by}`}
                    </p>
                    {entry.note && (
                        <p className="text-xs break-words text-muted-foreground">
                            {entry.note}
                        </p>
                    )}
                    {entry.void_reason && (
                        <p className="text-xs text-muted-foreground">
                            Dibatalkan: {entry.void_reason}
                        </p>
                    )}
                </div>
                <div className="flex flex-col items-end gap-1">
                    <span
                        className={
                            'font-medium tabular-nums ' +
                            (voided ? 'text-muted-foreground line-through' : '')
                        }
                    >
                        {incoming ? '+' : '−'}
                        {formatRupiah(entry.amount)}
                    </span>
                    {voided ? (
                        <Badge variant="outline">Batal</Badge>
                    ) : (
                        <span className="text-xs text-muted-foreground tabular-nums">
                            Saldo {formatRupiah(entry.balance_after)}
                        </span>
                    )}
                </div>
            </div>

            {!voiding && (
                <div className="flex flex-wrap gap-2">
                    <Button asChild size="sm" variant="outline">
                        <Link href={`/keuangan/slip-tabungan/${entry.id}`}>
                            <Printer />
                            Slip
                        </Link>
                    </Button>
                    {canSave && !voided && entry.kind !== 'BAYAR_TAGIHAN' && (
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            className="text-destructive"
                            onClick={() => setVoiding(true)}
                        >
                            <XCircle />
                            Batalkan
                        </Button>
                    )}
                </div>
            )}

            {voiding && (
                <Form
                    action={`/keuangan/tabungan/${entry.id}/batalkan`}
                    method="post"
                    errorBag={`void-saving-${entry.id}`}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setVoiding(false)}
                    className="space-y-2 rounded-md border border-destructive/40 p-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <Label htmlFor={`void-saving-${entry.id}`}>
                                Alasan pembatalan
                            </Label>
                            <Input
                                id={`void-saving-${entry.id}`}
                                name="reason"
                                maxLength={reasonMax}
                                placeholder="mis. Salah input jumlah"
                                required
                            />
                            <p className="text-xs text-muted-foreground">
                                Saldo dihitung ulang. Slip tetap tersimpan
                                dengan tanda BATAL.
                            </p>
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
                                    Batalkan transaksi
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
            )}
        </li>
    );
}
