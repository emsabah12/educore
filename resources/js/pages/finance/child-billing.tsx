import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import Heading from '@/components/heading';
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

type Payment = {
    id: string;
    receipt_number: string;
    paid_on: string;
    method: string;
    amount: number;
    items: string[];
};

type OrganizationBilling = {
    enrollment_id: string;
    organization: string;
    student_number: string | null;
    active: boolean;
    class: string | null;
    invoices: OpenInvoice[];
    outstanding: number;
    payments: Payment[];
};

type Props = {
    student: { id: string; name: string };
    organizations: OrganizationBilling[];
    outstanding: number;
    today: string;
    historyLimit: number;
};

const rupiah = new Intl.NumberFormat('id-ID');
const formatRupiah = (amount: number) => `Rp ${rupiah.format(amount)}`;

const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' });

function formatDate(value: string): string {
    const [year, month, day] = value.split('-').map(Number);

    return dateFormat.format(new Date(year, month - 1, day));
}

/**
 * Tagihan anak di portal orang tua/wali (PRD-003 FN-5 §19): sisa tagihan & riwayat pembayaran sah per
 * lembaga, kuitansi bisa dibuka/cetak. Hanya baca; pembayaran tetap lewat bendahara.
 */
export default function ChildBilling({
    student,
    organizations,
    outstanding,
    historyLimit,
}: Props) {
    return (
        <>
            <Head title={`Tagihan — ${student.name}`} />

            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 p-4">
                <div>
                    <Button asChild variant="ghost" size="sm">
                        <Link href={`/anak-saya/${student.id}`}>
                            <ArrowLeft />
                            {student.name}
                        </Link>
                    </Button>
                </div>

                <Heading
                    title="Tagihan"
                    description="Sisa tagihan dan riwayat pembayaran. Pembayaran dilakukan melalui bendahara sekolah; kuitansi bisa dibuka dan dicetak di sini."
                />

                {organizations.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Belum ada tagihan untuk {student.name}.
                    </p>
                ) : (
                    <>
                        <div className="rounded-xl border p-4">
                            <p className="text-xs text-muted-foreground">
                                Total sisa tagihan
                            </p>
                            <p className="text-2xl font-semibold tabular-nums">
                                {formatRupiah(outstanding)}
                            </p>
                        </div>

                        {organizations.map((organization) => (
                            <section
                                key={organization.enrollment_id}
                                className="space-y-3 rounded-xl border p-4"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <h2 className="font-semibold">
                                            {organization.organization}
                                            {!organization.active && (
                                                <Badge
                                                    variant="outline"
                                                    className="ml-2"
                                                >
                                                    Sudah keluar
                                                </Badge>
                                            )}
                                        </h2>
                                        <p className="text-sm text-muted-foreground">
                                            {[
                                                organization.class,
                                                organization.student_number &&
                                                    `NIS ${organization.student_number}`,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ') || ' '}
                                        </p>
                                    </div>
                                    <p className="text-right">
                                        <span className="block text-xs text-muted-foreground">
                                            Sisa
                                        </span>
                                        <span className="font-semibold tabular-nums">
                                            {formatRupiah(
                                                organization.outstanding,
                                            )}
                                        </span>
                                    </p>
                                </div>

                                <div className="space-y-2">
                                    <h3 className="text-sm font-medium">
                                        Belum lunas
                                    </h3>
                                    {organization.invoices.length === 0 ? (
                                        <p className="text-sm text-muted-foreground">
                                            Semua tagihan sudah lunas.
                                        </p>
                                    ) : (
                                        <ul className="divide-y rounded-lg border">
                                            {organization.invoices.map(
                                                (invoice) => (
                                                    <li
                                                        key={invoice.id}
                                                        className="flex flex-wrap items-start justify-between gap-2 p-3 text-sm"
                                                    >
                                                        <div className="min-w-0 space-y-0.5">
                                                            <p className="font-medium">
                                                                {
                                                                    invoice.description
                                                                }
                                                            </p>
                                                            <p className="text-muted-foreground">
                                                                Jatuh tempo{' '}
                                                                {formatDate(
                                                                    invoice.due_date,
                                                                )}
                                                                {invoice.paid_amount >
                                                                    0 &&
                                                                    ` · dibayar ${formatRupiah(invoice.paid_amount)} dari ${formatRupiah(invoice.amount)}`}
                                                            </p>
                                                            {invoice.overdue && (
                                                                <Badge variant="destructive">
                                                                    Lewat jatuh
                                                                    tempo
                                                                </Badge>
                                                            )}
                                                        </div>
                                                        <span className="font-semibold tabular-nums">
                                                            {formatRupiah(
                                                                invoice.outstanding,
                                                            )}
                                                        </span>
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <h3 className="text-sm font-medium">
                                        Riwayat pembayaran
                                    </h3>
                                    {organization.payments.length === 0 ? (
                                        <p className="text-sm text-muted-foreground">
                                            Belum ada pembayaran.
                                        </p>
                                    ) : (
                                        <ul className="divide-y rounded-lg border">
                                            {organization.payments.map(
                                                (payment) => (
                                                    <li
                                                        key={payment.id}
                                                        className="flex flex-wrap items-start justify-between gap-2 p-3 text-sm"
                                                    >
                                                        <div className="min-w-0 space-y-0.5">
                                                            <p className="font-medium">
                                                                {formatDate(
                                                                    payment.paid_on,
                                                                )}{' '}
                                                                ·{' '}
                                                                {payment.method}
                                                            </p>
                                                            {payment.items
                                                                .length > 0 && (
                                                                <p className="text-muted-foreground">
                                                                    {payment.items.join(
                                                                        ', ',
                                                                    )}
                                                                </p>
                                                            )}
                                                        </div>
                                                        <div className="text-right">
                                                            <p className="font-semibold tabular-nums">
                                                                {formatRupiah(
                                                                    payment.amount,
                                                                )}
                                                            </p>
                                                            <Link
                                                                href={`/anak-saya/${student.id}/kuitansi/${payment.id}`}
                                                                className="text-xs underline underline-offset-4"
                                                            >
                                                                Kuitansi{' '}
                                                                {
                                                                    payment.receipt_number
                                                                }
                                                            </Link>
                                                        </div>
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    )}
                                    {organization.payments.length >=
                                        historyLimit && (
                                        <p className="text-xs text-muted-foreground">
                                            Menampilkan {historyLimit}{' '}
                                            pembayaran terakhir.
                                        </p>
                                    )}
                                </div>
                            </section>
                        ))}
                    </>
                )}
            </div>
        </>
    );
}
