import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, ShieldCheck } from 'lucide-react';
import { useMemo, useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type OpenInvoice = {
    id: string;
    description: string;
    due_date: string;
    amount: number;
    paid_amount: number;
    outstanding: number;
    overdue: boolean;
};

type OrganizationInvoices = {
    enrollment_id: string;
    organization: string;
    invoices: OpenInvoice[];
};

type Method = {
    value: string;
    label: string;
    fee_is_percent: boolean;
    /** Rupiah (VA) atau basis poin (QRIS, 70 = 0,70%). */
    fee_value: number;
    fee_label: string;
};

type Props = {
    student: { id: string; name: string };
    organizations: OrganizationInvoices[];
    methods: Method[];
};

const rupiah = new Intl.NumberFormat('id-ID');
const formatRupiah = (amount: number) => `Rp ${rupiah.format(amount)}`;

const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' });

function formatDate(value: string): string {
    const [year, month, day] = value.split('-').map(Number);

    return dateFormat.format(new Date(year, month - 1, day));
}

/** Perkiraan biaya layanan; jumlah pastinya dihitung ulang server (PRD-006 §6). */
function feeFor(method: Method | undefined, amount: number): number {
    if (method === undefined) {
        return 0;
    }

    return method.fee_is_percent
        ? Math.ceil((amount * method.fee_value) / 10000)
        : method.fee_value;
}

/**
 * Bayar online (PRD-006 §4, MG-2): pilih tagihan (bawaan semua yang belum lunas) & metode VA/QRIS, lihat total
 * termasuk biaya layanan, lalu lanjut ke halaman pembayaran Midtrans. Jumlah dihitung ulang server.
 */
export default function ChildOnlinePay({
    student,
    organizations,
    methods,
}: Props) {
    const [enrollmentId, setEnrollmentId] = useState(
        organizations[0]?.enrollment_id ?? '',
    );
    const organization = organizations.find(
        (row) => row.enrollment_id === enrollmentId,
    );
    const [selected, setSelected] = useState<string[]>(
        organizations[0]?.invoices.map((invoice) => invoice.id) ?? [],
    );
    const [method, setMethod] = useState(methods[0]?.value ?? '');
    const chosenMethod = methods.find((row) => row.value === method);

    const amount = useMemo(
        () =>
            (organization?.invoices ?? [])
                .filter((invoice) => selected.includes(invoice.id))
                .reduce((sum, invoice) => sum + invoice.outstanding, 0),
        [organization, selected],
    );
    const fee = amount > 0 ? feeFor(chosenMethod, amount) : 0;

    const chooseOrganization = (id: string) => {
        setEnrollmentId(id);
        setSelected(
            organizations
                .find((row) => row.enrollment_id === id)
                ?.invoices.map((invoice) => invoice.id) ?? [],
        );
    };

    const toggle = (id: string) =>
        setSelected((current) =>
            current.includes(id)
                ? current.filter((value) => value !== id)
                : [...current, id],
        );

    return (
        <>
            <Head title={`Bayar online — ${student.name}`} />

            <div className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-4 p-4">
                <div>
                    <Button asChild variant="ghost" size="sm">
                        <Link href={`/anak-saya/${student.id}/tagihan`}>
                            <ArrowLeft />
                            Kembali ke tagihan
                        </Link>
                    </Button>
                </div>

                <Heading
                    title="Bayar online"
                    description={`Tagihan ${student.name}. Pilih tagihan dan cara bayar; Anda akan diarahkan ke halaman pembayaran Midtrans.`}
                />

                {organizations.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Tidak ada tagihan yang belum lunas.
                    </p>
                ) : (
                    <Form
                        action={`/anak-saya/${student.id}/bayar-online`}
                        method="post"
                        errorBag="onlinePay"
                        className="space-y-4"
                    >
                        {({ processing, errors }) => (
                            <>
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
                                <input
                                    type="hidden"
                                    name="method"
                                    value={method}
                                />

                                {organizations.length > 1 && (
                                    <fieldset className="space-y-2">
                                        <legend className="text-sm font-medium">
                                            Lembaga
                                        </legend>
                                        <div className="grid gap-2 sm:grid-cols-2">
                                            {organizations.map((row) => (
                                                <label
                                                    key={row.enrollment_id}
                                                    className={`flex cursor-pointer items-center gap-2 rounded-lg border p-3 text-sm ${row.enrollment_id === enrollmentId ? 'border-foreground bg-muted' : ''}`}
                                                >
                                                    <input
                                                        type="radio"
                                                        checked={
                                                            row.enrollment_id ===
                                                            enrollmentId
                                                        }
                                                        onChange={() =>
                                                            chooseOrganization(
                                                                row.enrollment_id,
                                                            )
                                                        }
                                                        className="size-4 accent-foreground"
                                                    />
                                                    {row.organization}
                                                </label>
                                            ))}
                                        </div>
                                    </fieldset>
                                )}

                                <fieldset className="space-y-2">
                                    <legend className="text-sm font-medium">
                                        Tagihan yang dibayar
                                    </legend>
                                    <ul className="divide-y rounded-xl border">
                                        {(organization?.invoices ?? []).map(
                                            (invoice) => (
                                                <li key={invoice.id}>
                                                    <label className="flex cursor-pointer items-start gap-3 p-3 text-sm hover:bg-muted/40">
                                                        <input
                                                            type="checkbox"
                                                            checked={selected.includes(
                                                                invoice.id,
                                                            )}
                                                            onChange={() =>
                                                                toggle(
                                                                    invoice.id,
                                                                )
                                                            }
                                                            className="mt-0.5 size-4 accent-foreground"
                                                        />
                                                        <span className="flex min-w-0 flex-1 flex-wrap items-start justify-between gap-2">
                                                            <span className="min-w-0">
                                                                {
                                                                    invoice.description
                                                                }
                                                                <span className="block text-xs text-muted-foreground">
                                                                    Jatuh tempo{' '}
                                                                    {formatDate(
                                                                        invoice.due_date,
                                                                    )}
                                                                    {invoice.overdue && (
                                                                        <Badge
                                                                            variant="destructive"
                                                                            className="ml-2"
                                                                        >
                                                                            Lewat
                                                                            jatuh
                                                                            tempo
                                                                        </Badge>
                                                                    )}
                                                                </span>
                                                            </span>
                                                            <span className="font-medium tabular-nums">
                                                                {formatRupiah(
                                                                    invoice.outstanding,
                                                                )}
                                                            </span>
                                                        </span>
                                                    </label>
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                    <InputError message={errors.invoice_ids} />
                                </fieldset>

                                <fieldset className="space-y-2">
                                    <legend className="text-sm font-medium">
                                        Cara bayar
                                    </legend>
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {methods.map((row) => (
                                            <label
                                                key={row.value}
                                                className={`flex cursor-pointer items-start gap-2 rounded-lg border p-3 text-sm ${row.value === method ? 'border-foreground bg-muted' : ''}`}
                                            >
                                                <input
                                                    type="radio"
                                                    checked={
                                                        row.value === method
                                                    }
                                                    onChange={() =>
                                                        setMethod(row.value)
                                                    }
                                                    className="mt-0.5 size-4 accent-foreground"
                                                />
                                                <span>
                                                    <span className="block font-medium">
                                                        {row.label}
                                                    </span>
                                                    <span className="block text-xs text-muted-foreground">
                                                        Biaya layanan:{' '}
                                                        {row.fee_label}
                                                    </span>
                                                </span>
                                            </label>
                                        ))}
                                    </div>
                                    <InputError message={errors.method} />
                                </fieldset>

                                <dl className="space-y-1 rounded-xl border p-4 text-sm">
                                    <div className="flex justify-between gap-2">
                                        <dt>Tagihan</dt>
                                        <dd className="tabular-nums">
                                            {formatRupiah(amount)}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between gap-2">
                                        <dt>Biaya layanan</dt>
                                        <dd className="tabular-nums">
                                            {formatRupiah(fee)}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between gap-2 border-t pt-1 text-base font-semibold">
                                        <dt>Total bayar</dt>
                                        <dd className="tabular-nums">
                                            {formatRupiah(amount + fee)}
                                        </dd>
                                    </div>
                                    <p className="pt-1 text-xs text-muted-foreground">
                                        Kuitansi dibuat sebesar tagihan setelah
                                        pembayaran diterima. Batas waktu bayar:{' '}
                                        {chosenMethod?.value === 'QRIS'
                                            ? '15 menit'
                                            : '24 jam'}
                                        .
                                    </p>
                                </dl>

                                <InputError
                                    message={
                                        errors.form ?? errors.enrollment_id
                                    }
                                />

                                <Button
                                    type="submit"
                                    disabled={
                                        processing ||
                                        selected.length === 0 ||
                                        method === ''
                                    }
                                    className="w-full"
                                >
                                    <ShieldCheck />
                                    {processing
                                        ? 'Menyiapkan pembayaran…'
                                        : `Lanjut bayar ${formatRupiah(amount + fee)}`}
                                </Button>
                                <p className="text-center text-xs text-muted-foreground">
                                    Pembayaran diproses oleh Midtrans. Sekolah
                                    tidak menerima nomor kartu atau PIN Anda.
                                </p>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}
