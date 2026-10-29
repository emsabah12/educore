import { Form, Head, Link, router } from '@inertiajs/react';
import { Printer, XCircle } from 'lucide-react';
import { useMemo, useState } from 'react';
import { StudentSearch } from '@/components/finance/student-search';
import Heading from '@/components/heading';
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

type StudentData = {
    student: {
        enrollment_id: string;
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
    canPay: boolean;
    today: string;
    methods: Option[];
    noteMax: number;
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
 * Kasir (PRD-003 §4, §6, FN-3): cari siswa → tagihan belum lunas → catat pembayaran (boleh cicil) →
 * kuitansi. Riwayat pembayaran & batal pembayaran. Server tetap memeriksa ulang setiap aksi.
 */
export default function Cashier({
    organizations,
    organizationId,
    student,
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
                    <StudentPanel
                        key={student.student.enrollment_id}
                        data={student}
                        organizationId={organizationId ?? ''}
                        canPay={canPay}
                        today={today}
                        methods={methods}
                        noteMax={noteMax}
                        voidReasonMax={voidReasonMax}
                    />
                )}
            </div>
        </>
    );
}

function StudentPanel({
    data,
    organizationId,
    canPay,
    today,
    methods,
    noteMax,
    voidReasonMax,
}: {
    data: StudentData;
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

                {invoices.length === 0 ? (
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
                    />
                ) : (
                    <InvoiceList invoices={invoices} />
                )}
            </section>

            <section className="space-y-3">
                <h2 className="text-base font-medium">Riwayat pembayaran</h2>
                {payments.length === 0 ? (
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
}: {
    organizationId: string;
    enrollmentId: string;
    invoices: OpenInvoice[];
    today: string;
    methods: Option[];
    noteMax: number;
}) {
    // Kosong = semua tagihan, dibayar urut jatuh tempo terlama.
    const [selected, setSelected] = useState<string[]>([]);
    const [amount, setAmount] = useState('');

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
                            <button
                                type="button"
                                className="justify-self-start text-xs font-medium underline underline-offset-4"
                                onClick={() => setAmount(rupiah.format(target))}
                            >
                                Isi lunas: {formatRupiah(target)}
                            </button>
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
                                defaultValue={methods[0]?.value}
                            >
                                {methods.map((method) => (
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
