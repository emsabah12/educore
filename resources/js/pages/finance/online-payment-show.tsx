import { Form, Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { FormDialog } from '@/components/form-dialog';
import { formatDateTime } from '@/components/finance/online-order';
import type { OnlineOrder } from '@/components/finance/online-order';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Order = OnlineOrder & {
    organization_id: string;
    overpaid_settled_at: string | null;
};

type Props = {
    order: Order;
    student: {
        name: string;
        class: string | null;
        student_number: string | null;
    };
    organization: string;
    receiptNumber: string | null;
    events: {
        received_at: string;
        transaction_status: string | null;
        signature_valid: boolean;
    }[];
    canManage: boolean;
    cashierUrl: string;
    noteMax: number;
    errors?: Record<string, string | undefined>;
};

const rupiah = new Intl.NumberFormat('id-ID');
const formatRupiah = (amount: number) => `Rp ${rupiah.format(amount)}`;

/**
 * Rincian satu pembayaran online untuk petugas (PRD-006 §9, MG-3): tagihan, jumlah, kuitansi, jejak notifikasi
 * Midtrans, cek status, dan tandai kelebihan bayar sudah dikembalikan (OD-MG-07).
 */
export default function OnlinePaymentShow({
    order,
    student,
    organization,
    receiptNumber,
    events,
    canManage,
    cashierUrl,
    noteMax,
    errors = {},
}: Props) {
    const [settling, setSettling] = useState(false);
    const [checking, setChecking] = useState(false);
    const base = `/keuangan/pembayaran-online/${order.id}`;
    const overpaidOpen =
        order.overpaid_amount > 0 && !order.overpaid_settled_at;

    return (
        <>
            <Head title={`Pembayaran online ${order.order_id}`} />

            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 p-4">
                <div>
                    <Button asChild variant="ghost" size="sm">
                        <Link href="/keuangan/pembayaran-online">
                            <ArrowLeft />
                            Pembayaran online
                        </Link>
                    </Button>
                </div>

                <Heading
                    title={student.name}
                    description={[
                        organization,
                        student.class,
                        student.student_number &&
                            `NIS ${student.student_number}`,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                />

                <section className="space-y-3 rounded-xl border p-4 text-sm">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Badge
                            variant={
                                order.status === 'LUNAS'
                                    ? 'default'
                                    : order.status === 'MENUNGGU'
                                      ? 'secondary'
                                      : 'outline'
                            }
                        >
                            {order.status_label}
                        </Badge>
                        <span className="font-mono text-xs text-muted-foreground">
                            {order.order_id}
                        </span>
                    </div>

                    <dl className="grid grid-cols-[10rem_1fr] gap-x-3 gap-y-1">
                        <dt className="text-muted-foreground">Dibuat</dt>
                        <dd>{formatDateTime(order.created_at)}</dd>
                        <dt className="text-muted-foreground">Metode</dt>
                        <dd>{order.method_label}</dd>
                        {order.status === 'MENUNGGU' && (
                            <>
                                <dt className="text-muted-foreground">
                                    Batas bayar
                                </dt>
                                <dd>{formatDateTime(order.expires_at)}</dd>
                            </>
                        )}
                        {order.paid_at && (
                            <>
                                <dt className="text-muted-foreground">Lunas</dt>
                                <dd>{formatDateTime(order.paid_at)}</dd>
                            </>
                        )}
                        <dt className="text-muted-foreground">Tagihan</dt>
                        <dd className="tabular-nums">
                            {formatRupiah(order.invoice_amount)}
                        </dd>
                        <dt className="text-muted-foreground">Biaya layanan</dt>
                        <dd className="tabular-nums">
                            {formatRupiah(order.fee_amount)} (dibayar orang tua)
                        </dd>
                        <dt className="text-muted-foreground">Total</dt>
                        <dd className="font-semibold tabular-nums">
                            {formatRupiah(order.gross_amount)}
                        </dd>
                        {receiptNumber && order.payment_id && (
                            <>
                                <dt className="text-muted-foreground">
                                    Kuitansi
                                </dt>
                                <dd>
                                    <Link
                                        href={`/keuangan/kuitansi/${order.payment_id}`}
                                        className="font-mono underline underline-offset-4"
                                    >
                                        {receiptNumber}
                                    </Link>
                                </dd>
                            </>
                        )}
                    </dl>

                    <ul className="divide-y rounded-lg border">
                        {order.invoices.map((invoice) => (
                            <li
                                key={invoice.id}
                                className="flex justify-between gap-2 p-2"
                            >
                                <span className="min-w-0">
                                    {invoice.description}
                                </span>
                                <span className="tabular-nums">
                                    {formatRupiah(invoice.outstanding)}
                                </span>
                            </li>
                        ))}
                    </ul>

                    {order.overpaid_amount > 0 && (
                        <p
                            className={`rounded-md border p-3 ${overpaidOpen ? 'border-amber-300 bg-amber-50 text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100' : 'text-muted-foreground'}`}
                        >
                            {overpaidOpen
                                ? `Kelebihan bayar ${formatRupiah(order.overpaid_amount)}: sebagian tagihan sudah dibayar lewat kasir sebelum pembayaran online ini diterima. Kembalikan ke orang tua (tunai, transfer, atau refund di dashboard Midtrans), lalu tandai di sini.`
                                : `Kelebihan bayar ${formatRupiah(order.overpaid_amount)} sudah dikembalikan${order.overpaid_settled_at ? ` (${formatDateTime(order.overpaid_settled_at)})` : ''}.`}
                        </p>
                    )}

                    <InputError message={errors.form} />

                    <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2 sm:flex">
                        {canManage && order.status !== 'LUNAS' && (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={checking}
                                onClick={() =>
                                    router.post(
                                        `${base}/cek`,
                                        {},
                                        {
                                            preserveScroll: true,
                                            onStart: () => setChecking(true),
                                            onFinish: () => setChecking(false),
                                        },
                                    )
                                }
                            >
                                <RefreshCw />
                                Cek status di Midtrans
                            </Button>
                        )}
                        {canManage && overpaidOpen && (
                            <Button
                                type="button"
                                onClick={() => setSettling(true)}
                            >
                                Tandai sudah dikembalikan
                            </Button>
                        )}
                        <Button asChild variant="ghost">
                            <Link href={cashierUrl}>Buka di kasir</Link>
                        </Button>
                    </div>
                </section>

                <section className="space-y-2">
                    <h2 className="text-sm font-medium">Notifikasi Midtrans</h2>
                    {events.length === 0 ? (
                        <p className="rounded-xl border border-dashed p-4 text-center text-sm text-muted-foreground">
                            Belum ada notifikasi dari Midtrans.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-xl border text-sm">
                            {events.map((event, index) => (
                                <li
                                    key={index}
                                    className="flex flex-wrap items-center justify-between gap-2 p-3"
                                >
                                    <span>
                                        {formatDateTime(event.received_at)} ·{' '}
                                        {event.transaction_status ?? '-'}
                                    </span>
                                    {event.signature_valid ? (
                                        <Badge variant="secondary">
                                            Tanda tangan valid
                                        </Badge>
                                    ) : (
                                        <Badge variant="destructive">
                                            Tanda tangan tidak valid (diabaikan)
                                        </Badge>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <FormDialog
                    open={settling}
                    onOpenChange={setSettling}
                    title="Tandai kelebihan bayar sudah dikembalikan"
                    description={`${formatRupiah(order.overpaid_amount)} untuk ${student.name}. Pengembalian uangnya dilakukan di luar aplikasi.`}
                >
                    <Form
                        action={`${base}/kelebihan-dikembalikan`}
                        method="post"
                        errorBag="settleOverpaid"
                        options={{ preserveScroll: true }}
                        onSuccess={() => setSettling(false)}
                        className="space-y-3"
                    >
                        {({ processing, errors: formErrors }) => (
                            <>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="settle-note">
                                        Catatan (opsional)
                                    </Label>
                                    <Input
                                        id="settle-note"
                                        name="note"
                                        maxLength={noteMax}
                                        placeholder="mis. Dikembalikan tunai ke Ibu Siti, 5 Nov"
                                    />
                                    <InputError
                                        message={
                                            formErrors.note ?? formErrors.form
                                        }
                                    />
                                </div>
                                <Button type="submit" disabled={processing}>
                                    Simpan
                                </Button>
                            </>
                        )}
                    </Form>
                </FormDialog>
            </div>
        </>
    );
}
