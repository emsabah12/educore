import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { formatDateTime } from '@/components/finance/online-order';
import type { OnlineOrder } from '@/components/finance/online-order';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Props = {
    student: { id: string; name: string };
    order: OnlineOrder;
    organization: string;
    errors?: Record<string, string | undefined>;
};

const rupiah = new Intl.NumberFormat('id-ID');
const formatRupiah = (amount: number) => `Rp ${rupiah.format(amount)}`;

/**
 * Status pembayaran online (PRD-006 §4, MG-2): menunggu (lanjutkan / cek status / batalkan), lunas (kuitansi),
 * kedaluwarsa / gagal / dibatalkan. Status resmi datang dari Midtrans lewat server, bukan dari halaman ini.
 */
export default function ChildOnlineStatus({
    student,
    order,
    organization,
    errors = {},
}: Props) {
    const [busy, setBusy] = useState(false);
    const base = `/anak-saya/${student.id}/bayar-online/${order.id}`;
    const pending = order.status === 'MENUNGGU';

    const post = (path: string) =>
        router.post(
            `${base}/${path}`,
            {},
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            },
        );

    return (
        <>
            <Head title={`Status pembayaran — ${student.name}`} />

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
                    title="Status pembayaran online"
                    description={`${student.name} · ${organization}`}
                />

                <section className="space-y-3 rounded-xl border p-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Badge
                            variant={
                                order.status === 'LUNAS'
                                    ? 'default'
                                    : pending
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

                    {pending && (
                        <p className="text-sm">
                            Selesaikan pembayaran lewat {order.method_label}{' '}
                            sebelum {formatDateTime(order.expires_at)}. Status
                            berubah otomatis setelah Midtrans menerima
                            pembayaran (biasanya beberapa detik sampai beberapa
                            menit).
                        </p>
                    )}
                    {order.status === 'LUNAS' && (
                        <p className="text-sm">
                            Pembayaran diterima
                            {order.paid_at &&
                                ` pada ${formatDateTime(order.paid_at)}`}
                            . Terima kasih.
                        </p>
                    )}
                    {(order.status === 'KEDALUWARSA' ||
                        order.status === 'GAGAL' ||
                        order.status === 'DIBATALKAN') && (
                        <p className="text-sm text-muted-foreground">
                            Tidak ada uang yang ditarik untuk pesanan ini.
                            Tagihan tetap bisa dibayar kapan saja.
                        </p>
                    )}
                    {order.overpaid_amount > 0 && (
                        <p className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                            Sebagian tagihan sudah dibayar lewat kasir sebelum
                            pembayaran online ini diterima. Kelebihan{' '}
                            {formatRupiah(order.overpaid_amount)} akan
                            dikembalikan bendahara sekolah.
                        </p>
                    )}

                    <ul className="divide-y rounded-lg border text-sm">
                        {order.invoices.map((invoice) => (
                            <li
                                key={invoice.id}
                                className="flex justify-between gap-2 p-3"
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

                    <dl className="space-y-1 text-sm">
                        <div className="flex justify-between gap-2">
                            <dt>Tagihan</dt>
                            <dd className="tabular-nums">
                                {formatRupiah(order.invoice_amount)}
                            </dd>
                        </div>
                        <div className="flex justify-between gap-2">
                            <dt>Biaya layanan ({order.method_label})</dt>
                            <dd className="tabular-nums">
                                {formatRupiah(order.fee_amount)}
                            </dd>
                        </div>
                        <div className="flex justify-between gap-2 border-t pt-1 font-semibold">
                            <dt>Total</dt>
                            <dd className="tabular-nums">
                                {formatRupiah(order.gross_amount)}
                            </dd>
                        </div>
                    </dl>

                    <InputError message={errors.form} />

                    <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2 sm:flex">
                        {pending && (
                            <>
                                <Button asChild>
                                    <a href={`${base}/lanjutkan`}>
                                        Lanjutkan pembayaran
                                    </a>
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() => post('cek')}
                                >
                                    <RefreshCw />
                                    Cek status
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    className="text-destructive"
                                    disabled={busy}
                                    onClick={() => post('batalkan')}
                                >
                                    Batalkan
                                </Button>
                            </>
                        )}
                        {order.status === 'LUNAS' && order.payment_id && (
                            <Button asChild>
                                <Link
                                    href={`/anak-saya/${student.id}/kuitansi/${order.payment_id}`}
                                >
                                    Lihat kuitansi
                                </Link>
                            </Button>
                        )}
                    </div>
                </section>
            </div>
        </>
    );
}
